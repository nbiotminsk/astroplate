<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\DTO\ChannelReadingDTO;
use TelegramBot\DTO\DeviceDTO;
use TelegramBot\DTO\HistoricalValueDTO;
use TelegramBot\Repository\UserMeterRepositoryInterface;

class ReportService
{
    public function __construct(
        private ?UserMeterRepositoryInterface $userMeterRepo = null,
        private ?MeterService $meterService = null
    ) {}

    public function buildReport(array $config, DeviceDTO $device): string
    {
        $deviceId = $device->deviceId;
        $timezone = $config['timezone'] ?? 'Europe/Minsk';

        $title = $device->address ?: $device->name;
        $headerPrefix = (str_starts_with($title, '📍') || str_starts_with($title, '📱')) ? '' : '📍 ';

        $lines = [];
        $lines[] = "{$headerPrefix}<b>{$title}</b>\n";

        // 1. Текущие показания получаем ПЕРВИЧНО из GET /api/v1/devices/{device_id}/info
        $infoResp = UnicBoard::getDeviceInfo($config, $deviceId);
        $httpStatus = $infoResp['http_status'] ?? 0;

        // Если сервер UnicBoard недоступен (сетевой таймаут / сбой подключения)
        if ($httpStatus === 0 && !$infoResp['ok']) {
            throw new \TelegramBot\Exception\ApiUnavailableException();
        }

        $infoPayload = $infoResp['payload'] ?? null;
        $currentReadings = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayload);

        // /values не нужен для успешного получения текущего показания. Запрашиваем
        // архив только как явно обозначенный фолбэк, если /info не дал онлайн-данных.
        $historyRecords = [];
        $historyByChannel = [];
        if (empty($currentReadings)) {
            $valuesResp = UnicBoard::getDeviceValues($config, $deviceId, 50);
            $historyRecords = MeterService::extractHistoricalRecordsFromValues($valuesResp['payload'] ?? []);

            foreach ($historyRecords as $rec) {
                $historyByChannel[$rec->channelNumber][] = $rec;
            }
            foreach ($historyByChannel as $chNum => &$hList) {
                usort($hList, static function (HistoricalValueDTO $a, HistoricalValueDTO $b) {
                    return MeterService::parseUtcTimestamp($b->date) - MeterService::parseUtcTimestamp($a->date);
                });
            }
            unset($hList);
        }

        $activeChannels = $device->activeChannels;
        $deviceSerial = $device->serialNumber !== '' ? $device->serialNumber : $deviceId;

        // 3. Формируем блок показаний по каналам
        if (!empty($currentReadings)) {
            $latestDate = null;
            $inactivityNotes = [];

            foreach ($currentReadings as $chNum => $reading) {
                if ($activeChannels !== null && !empty($activeChannels) && !in_array((int) $chNum, $activeChannels, true)) {
                    continue;
                }

                $lastVal = $reading->lastValue;
                $lastValDate = $reading->lastValueDate;
                if ($lastValDate !== null && ($latestDate === null || $lastValDate > $latestDate)) {
                    $latestDate = $lastValDate;
                }

                // Конфигурация канала (номер счетчика, начальные показания, база API)
                $chConfig = $device->channels[$chNum] ?? $device->channels[(string) $chNum] ?? null;
                $userInitial = isset($chConfig['user_initial']) && $chConfig['user_initial'] !== null
                    ? (float) $chConfig['user_initial']
                    : (isset($device->initialValues[(string) $chNum]) ? (float) $device->initialValues[(string) $chNum] : null);
                $baseApiVal = isset($chConfig['base_api_value']) && $chConfig['base_api_value'] !== null
                    ? (float) $chConfig['base_api_value']
                    : null;
                $meterNum = $chConfig['meter_number'] ?? null;

                if ($lastVal !== null && $userInitial !== null && ($baseApiVal === null || ($baseApiVal == 0.0 && $lastVal > 0))) {
                    $baseApiVal = (float) $lastVal;
                    if (!empty($device->serialNumber)) {
                        Storage::updateDeviceChannelBaseApiValue($device->serialNumber, (string) $chNum, $baseApiVal);
                    }
                }

                $displayVal = $lastVal !== null
                    ? MeterService::calculateDisplayValue((float) $lastVal, $userInitial !== null ? (float) $userInitial : null, $baseApiVal)
                    : null;

                $valWithUnit = $displayVal !== null ? number_format($displayVal, 2, '.', '') . ' m³' : '—';

                // Обновляем кэш расхода и выводим дельту расхода за сегодня
                $diffStr = '';
                if ($lastVal !== null) {
                    $svc = $this->meterService ?? new MeterService();
                    $consInfo = $svc->getMeterConsumptionInfo($config, $deviceId, (int) $chNum, $lastVal, $lastValDate, $historyByChannel[$chNum] ?? [], false);

                    if (!empty($consInfo['last_change_diff']) && (float) $consInfo['last_change_diff'] > 0 && !empty($consInfo['last_change_date'])) {
                        $tz = new \DateTimeZone($timezone);
                        $changeTs = MeterService::parseUtcTimestamp((string) $consInfo['last_change_date']);
                        $changeDay = (new \DateTimeImmutable("@{$changeTs}"))->setTimezone($tz)->format('Y-m-d');
                        $todayDay = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

                        if ($changeDay === $todayDay) {
                            $diffVal = (float) $consInfo['last_change_diff'];
                            $diffStr = " (<b>+" . number_format($diffVal, 2, '.', '') . " m³</b>)";
                        }
                    }
                }

                if ($meterNum !== null && $meterNum !== '') {
                    $meterLabel = "{$chNum}. 💧 Счетчик № {$meterNum}";
                } elseif (count($currentReadings) > 1) {
                    $meterLabel = "{$chNum}. 💧 Вход {$chNum}";
                } else {
                    $meterLabel = "{$chNum}. 💧 Счетчик № {$deviceSerial}";
                }

                if ($reading->isInactive() && $reading->lastDateEventNoData !== null) {
                    $inactivityDate = MeterService::formatDate($reading->lastDateEventNoData, 'd.m.Y', $timezone);
                    $inactivityNotes[] = "<i>(нет данных с {$inactivityDate})</i>";
                }

                $lines[] = "{$meterLabel}: <b>{$valWithUnit}</b>{$diffStr}";
            }

            // Единый вывод даты и времени для прибора
            $dateStr = $latestDate ? MeterService::formatDate($latestDate, 'd.m.Y H:i', $timezone) : '—';
            $inactivityStr = !empty($inactivityNotes) ? ' ' . implode(', ', array_unique($inactivityNotes)) : '';
            $lines[] = "\n🕒 {$dateStr}{$inactivityStr}";
        } elseif (!empty($historyByChannel)) {
            // Фолбэк: если в /info нет валидных онлайн-показаний, но в /values есть история
            $lines[] = "📊 <b>Последние сохраненные показания (архив):</b>";
            ksort($historyByChannel);
            $latestDate = null;

            foreach ($historyByChannel as $chNum => $history) {
                if ($activeChannels !== null && !empty($activeChannels) && !in_array((int) $chNum, $activeChannels, true)) {
                    continue;
                }

                $latest = $history[0] ?? null;
                $val = $latest ? $latest->value : null;
                if ($latest && $latest->date && ($latestDate === null || $latest->date > $latestDate)) {
                    $latestDate = $latest->date;
                }

                $chConfig = $device->channels[$chNum] ?? $device->channels[(string) $chNum] ?? null;
                $userInitial = isset($chConfig['user_initial']) && $chConfig['user_initial'] !== null
                    ? (float) $chConfig['user_initial']
                    : (isset($device->initialValues[(string) $chNum]) ? (float) $device->initialValues[(string) $chNum] : null);
                $baseApiVal = isset($chConfig['base_api_value']) && $chConfig['base_api_value'] !== null
                    ? (float) $chConfig['base_api_value']
                    : null;
                $meterNum = $chConfig['meter_number'] ?? null;

                if ($val !== null && $userInitial !== null && ($baseApiVal === null || ($baseApiVal == 0.0 && $val > 0))) {
                    $baseApiVal = (float) $val;
                    if (!empty($device->serialNumber)) {
                        Storage::updateDeviceChannelBaseApiValue($device->serialNumber, (string) $chNum, $baseApiVal);
                    }
                }

                $displayVal = $val !== null
                    ? MeterService::calculateDisplayValue((float) $val, $userInitial !== null ? (float) $userInitial : null, $baseApiVal)
                    : null;

                $valWithUnit = $displayVal !== null ? number_format($displayVal, 2, '.', '') . ' m³' : '—';

                if ($meterNum !== null && $meterNum !== '') {
                    $meterLabel = "{$chNum}. 💧 Счетчик № {$meterNum}";
                } else {
                    $meterLabel = "{$chNum}. 💧 Вход {$chNum}";
                }

                $lines[] = "{$meterLabel}: <b>{$valWithUnit}</b>";
            }

            $dateStr = $latestDate ? MeterService::formatDate($latestDate, 'd.m.Y H:i', $timezone) : '—';
            $lines[] = "\n🕒 {$dateStr}";
        } else {
            $lines[] = "• Показания: нет данных";
        }

        if (empty($currentReadings) && empty($historyRecords)) {
            $lines[] = "\n⚠️ Не удалось получить данные по устройству {$deviceId}.";
        }

        return implode("\n", $lines);
    }

    /**
     * Архив за текущий месяц (от 1 числа до текущего дня)
     */
    public function buildMonthReport(array $config, DeviceDTO $device): string
    {
        $name = $device->name;
        $deviceId = $device->deviceId;
        $timezone = $config['timezone'] ?? 'Europe/Minsk';

        $firstDay = date('01.m.Y 00:00');
        $lastDay = date('d.m.Y H:i');

        $lines = [];
        $lines[] = "📅 <b>Архив за текущий месяц ({$name})</b>";
        $lines[] = "Период: <b>{$firstDay}</b> — <b>{$lastDay}</b>\n";

        $startMonthTs = strtotime(date('Y-m-01 00:00:00'));
        $endMonthTs = time();

        // 1. Запрашиваем исторические значения за текущий месяц
        $valuesResp = UnicBoard::getDeviceValues($config, $deviceId, 100, date('Y-m-01\T00:00:00'));
        $valuesStatus = $valuesResp['http_status'] ?? 0;

        // 2. Запрашиваем текущие онлайн показания из /info
        $infoResp = UnicBoard::getDeviceInfo($config, $deviceId);
        $infoStatus = $infoResp['http_status'] ?? 0;

        if ($valuesStatus === 0 && $infoStatus === 0 && !$valuesResp['ok'] && !$infoResp['ok']) {
            $lines[] = "\n⚠️ <i>Сервер сбора данных временно недоступен. Пожалуйста, попробуйте снова через минуту.</i>";
            return implode("\n", $lines);
        }

        $historyRecords = MeterService::extractHistoricalRecordsFromValues($valuesResp['payload'] ?? []);
        $infoPayload = $infoResp['payload'] ?? null;
        $currentReadings = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayload);

        $channelsMonthData = [];
        foreach ($historyRecords as $rec) {
            $ts = MeterService::parseUtcTimestamp($rec->date);
            // Расход — только по физическим DEVICE_DATA; интерполяция остаётся
            // доступной в обычном архивном фолбэке и явно помечается там.
            if ($ts >= $startMonthTs && $ts <= $endMonthTs && MeterService::isPhysicalHistoricalReading($rec)) {
                $channelsMonthData[$rec->channelNumber][] = $rec;
            }
        }
        ksort($channelsMonthData);

        // Если для каналов из /info нет записей в /values, инициализируем их
        foreach ($currentReadings as $chNum => $reading) {
            if (!isset($channelsMonthData[$chNum])) {
                $channelsMonthData[$chNum] = [];
            }
        }
        ksort($channelsMonthData);

        $activeChannels = $device->activeChannels;
        if (!empty($channelsMonthData)) {
            $totalChannels = count($channelsMonthData);
            foreach ($channelsMonthData as $chNum => $records) {
                if ($activeChannels !== null && !empty($activeChannels) && !in_array((int) $chNum, $activeChannels, true)) {
                    continue;
                }

                usort($records, static function (HistoricalValueDTO $a, HistoricalValueDTO $b) {
                    return MeterService::parseUtcTimestamp($b->date) - MeterService::parseUtcTimestamp($a->date);
                });

                $latestHistory = reset($records) ?: null;
                $earliestInMonth = end($records) ?: null;

                // Начало месяца
                $rawValStart = $earliestInMonth ? (float) $earliestInMonth->value : null;
                $dateStart = $earliestInMonth ? $earliestInMonth->date : null;
                $dateStartStr = $dateStart ? MeterService::formatDate($dateStart, 'd.m.Y H:i', $timezone) : '—';

                // Конец периода: приоритет текущему показанию из /info, если оно новее
                $currentCh = $currentReadings[$chNum] ?? null;
                if ($currentCh && $currentCh->hasReading()) {
                    $rawValEnd = (float) $currentCh->lastValue;
                    $dateEnd = $currentCh->lastValueDate;
                } elseif ($latestHistory) {
                    $rawValEnd = (float) $latestHistory->value;
                    $dateEnd = $latestHistory->date;
                } else {
                    $rawValEnd = null;
                    $dateEnd = null;
                }
                $dateEndStr = $dateEnd ? MeterService::formatDate($dateEnd, 'd.m.Y H:i', $timezone) : '—';

                // Конфигурация канала (номер счетчика, начальные показания, база API)
                $chConfig = $device->channels[$chNum] ?? $device->channels[(string) $chNum] ?? null;
                $userInitial = isset($chConfig['user_initial']) && $chConfig['user_initial'] !== null
                    ? (float) $chConfig['user_initial']
                    : (isset($device->initialValues[(string) $chNum]) ? (float) $device->initialValues[(string) $chNum] : null);
                $baseApiVal = isset($chConfig['base_api_value']) && $chConfig['base_api_value'] !== null
                    ? (float) $chConfig['base_api_value']
                    : null;
                $meterNum = $chConfig['meter_number'] ?? null;

                if ($rawValEnd !== null && $userInitial !== null && ($baseApiVal === null || ($baseApiVal == 0.0 && $rawValEnd > 0))) {
                    $baseApiVal = (float) $rawValEnd;
                    if (!empty($device->serialNumber)) {
                        Storage::updateDeviceChannelBaseApiValue($device->serialNumber, (string) $chNum, $baseApiVal);
                    }
                }

                $displayValEnd = $rawValEnd !== null
                    ? MeterService::calculateDisplayValue($rawValEnd, $userInitial !== null ? (float) $userInitial : null, $baseApiVal)
                    : null;

                if ($rawValStart !== null) {
                    $displayValStart = MeterService::calculateDisplayValue($rawValStart, $userInitial !== null ? (float) $userInitial : null, $baseApiVal);
                } elseif ($userInitial !== null) {
                    $displayValStart = (float) $userInitial;
                    $dateStartStr = date('01.m.Y 00:00');
                } elseif ($displayValEnd !== null) {
                    $displayValStart = $displayValEnd;
                    $dateStartStr = $dateEndStr;
                } else {
                    $displayValStart = null;
                }

                $isFluo = MeterService::isFluoDevice($infoPayload, $device);
                $deviceSerial = $device->serialNumber !== '' ? $device->serialNumber : $deviceId;

                if ($isFluo) {
                    $meterLabel = "Счетчик Fluo № {$deviceSerial}";
                    $prefix = "";
                } elseif ($meterNum !== null && $meterNum !== '') {
                    $meterLabel = "💧 Счетчик № {$meterNum}";
                    $prefix = "{$chNum}. ";
                } elseif ($totalChannels > 1) {
                    $meterLabel = "Канал {$chNum}";
                    $prefix = "{$chNum}. ";
                } else {
                    $meterLabel = "Счетчик № {$deviceSerial}";
                    $prefix = "";
                }

                $valStartStr = $displayValStart !== null ? number_format($displayValStart, 2, '.', '') . " m³" : '—';
                $valEndStr = $displayValEnd !== null ? number_format($displayValEnd, 2, '.', '') . " m³" : '—';

                $lines[] = "<b>{$prefix}{$meterLabel}:</b>";
                $lines[] = "  • Нач. месяца ({$dateStartStr}): <b>{$valStartStr}</b>";
                $lines[] = "  • Кон. периода ({$dateEndStr}): <b>{$valEndStr}</b>";

                if ($displayValEnd !== null && $displayValStart !== null) {
                    $monthConsumption = $displayValEnd - $displayValStart;
                    $formattedConsumption = ($monthConsumption >= 0 ? '+' : '') . number_format($monthConsumption, 2, '.', '');
                    $lines[] = "  • 📊 <b>Расход за месяц: {$formattedConsumption} m³</b>";
                }

                // Кэш расхода
                $svc = $this->meterService ?? new MeterService();
                $lastCons = $svc->getMeterConsumptionInfo($config, $deviceId, (int) $chNum, $rawValEnd, $dateEnd, $records);

                if ($lastCons && !empty($lastCons['last_change_date'])) {
                    $diffVal = isset($lastCons['last_change_diff']) ? number_format((float) $lastCons['last_change_diff'], 2, '.', '') : '0.00';
                    $lines[] = "\n  ℹ️ Последний расход зафиксирован: " . MeterService::formatDate($lastCons['last_change_date'], 'd.m.Y', $timezone) . " (на {$diffVal} m³)";
                } else {
                    $lines[] = "\n  ℹ️ Последний расход не обнаружен.";
                }

                $lines[] = "";
            }
        } else {
            $lines[] = "📊 В текущем месяце записей не найдено.";
        }

        return implode("\n", $lines);
    }

    public function buildDiagnosticReport(array $config, DeviceDTO $device): string
    {
        $deviceSerial = $device->serialNumber ?? '—';
        $addr = $device->address ?: $device->name;

        $lines = [];
        $lines[] = "⚙️ <b>Диагностика модема № {$deviceSerial}</b>";
        if (!empty($addr)) {
            $lines[] = "📍 <i>{$addr}</i>";
        }
        $lines[] = "\nВыберите нужный раздел для диагностики:";

        return implode("\n", $lines);
    }

    public function buildDiagChannelsReport(array $config, DeviceDTO $device): string
    {
        $timezone = $config['timezone'] ?? 'Europe/Minsk';
        $deviceId = $device->deviceId;
        $deviceSerial = $device->serialNumber ?? '—';
        $addr = $device->address ?: $device->name;

        $infoResp = UnicBoard::getDeviceInfo($config, $deviceId);
        $payload = $infoResp['payload'] ?? [];

        $lines = [];
        $lines[] = "📊 <b>Каналы и импульсы (Модем № {$deviceSerial})</b>";
        if (!empty($addr)) {
            $lines[] = "📍 <i>{$addr}</i>\n";
        } else {
            $lines[] = "";
        }

        $channels = $payload['device_channel'] ?? [];

        $hasValuesInInfo = false;
        if (!empty($channels)) {
            foreach ($channels as $ch) {
                if (isset($ch['device_meter'][0]['last_value']) && is_numeric($ch['device_meter'][0]['last_value'])) {
                    $hasValuesInInfo = true;
                    break;
                }
            }
        }

        $historyByChannel = [];
        if (!$hasValuesInInfo) {
            $valuesResp = UnicBoard::getDeviceValues($config, $deviceId, 10);
            $historyRecords = MeterService::extractHistoricalRecordsFromValues($valuesResp['payload'] ?? []);
            foreach ($historyRecords as $rec) {
                $historyByChannel[$rec->channelNumber][] = $rec;
            }
            foreach ($historyByChannel as $chN => &$hList) {
                usort($hList, static function (HistoricalValueDTO $a, HistoricalValueDTO $b) {
                    return MeterService::parseUtcTimestamp($b->date) - MeterService::parseUtcTimestamp($a->date);
                });
            }
            unset($hList);
        }

        if (empty($channels) && !empty($historyByChannel)) {
            foreach ($historyByChannel as $chNumInt => $histList) {
                $latestH = $histList[0] ?? null;
                $channels[] = [
                    'serial_number' => $chNumInt,
                    'device_meter' => [
                        [
                            'last_value' => $latestH ? $latestH->value : null,
                            'last_value_date' => $latestH ? $latestH->date : null,
                            'unit_multiplier' => 1.0,
                            'value_multiplier' => 1.0,
                        ]
                    ]
                ];
            }
        }

        if (empty($channels) && !empty($device->channels)) {
            foreach ($device->channels as $chNumStr => $chConf) {
                $chNumInt = (int) $chNumStr;
                $channels[] = [
                    'serial_number' => $chNumInt,
                    'device_meter' => [
                        [
                            'last_value' => $chConf['base_api_value'] ?? null,
                            'unit_multiplier' => 1.0,
                            'value_multiplier' => 1.0,
                        ]
                    ]
                ];
            }
        }

        $activeChannels = $device->activeChannels ?? [1, 2];

        if (!empty($channels)) {
            foreach ($channels as $ch) {
                $chNum = (int) ($ch['serial_number'] ?? 1);
                $meters = $ch['device_meter'] ?? [];
                $meterBilling = $meters[0] ?? [];

                $lastVal = isset($meterBilling['last_value']) && is_numeric($meterBilling['last_value']) ? (float) $meterBilling['last_value'] : null;
                $lastValDate = $meterBilling['last_value_date'] ?? null;

                if (($lastVal === null || $lastValDate === null) && !empty($historyByChannel[$chNum])) {
                    $latestH = $historyByChannel[$chNum][0] ?? null;
                    if ($latestH) {
                        $lastVal ??= (float) $latestH->value;
                        $lastValDate ??= $latestH->date;
                    }
                }

                $unitMultiplier = isset($meterBilling['unit_multiplier']) && is_numeric($meterBilling['unit_multiplier']) ? (float) $meterBilling['unit_multiplier'] : 1.0;
                $valueMultiplier = isset($meterBilling['value_multiplier']) && is_numeric($meterBilling['value_multiplier']) ? (float) $meterBilling['value_multiplier'] : 1.0;

                $litersPerPulse = $unitMultiplier * 10.0 * $valueMultiplier;
                $m3PerPulse = $litersPerPulse / 1000.0;
                $pulses = ($m3PerPulse > 0 && $lastVal !== null) ? (int) round($lastVal / $m3PerPulse) : null;

                $chConfig = $device->channels[$chNum] ?? $device->channels[(string) $chNum] ?? null;
                $meterNum = $chConfig['meter_number'] ?? null;
                $meterLabel = $meterNum ? " (№ <code>{$meterNum}</code>)" : "";

                $formattedVal = $lastVal !== null ? number_format($lastVal, 2, '.', '') . ' m³' : '—';
                $pulsesStr = $pulses !== null ? "{$pulses} имп." : '—';
                $dateStr = MeterService::formatDate($lastValDate, 'd.m.Y H:i', $timezone);
                $multiplierLiters = round($litersPerPulse, 3);

                $statusActive = in_array($chNum, $activeChannels, true) ? "✅ Активен" : "⏸ Отключен в боте";

                $lines[] = "<b>Вход {$chNum}{$meterLabel}</b> [{$statusActive}]:";
                $lines[] = "• Вес импульса: <b>{$multiplierLiters} л/имп</b> ({$m3PerPulse} м³/имп)";
                $lines[] = "• Число импульсов: <b>{$pulsesStr}</b>";
                $lines[] = "• Объём в базе: <b>{$formattedVal}</b>";
                $lines[] = "• Дата опроса: <b>{$dateStr}</b>\n";
            }
        } else {
            $lines[] = "ℹ️ Информация по каналам не найдена.\n";
        }

        $protocol = $payload['data_gateway_network_device']['protocol']['name'] ?? 'SMP_M';
        $networkType = $payload['data_gateway_network_device']['network']['type_network'] ?? 'input';
        $modemName = ($payload['device_modification']['device_modification_type']['name_ru'] ?? 'Модем') . ' ' . ($payload['device_modification']['name'] ?? '');

        $lines[] = "📡 Протокол передачи: <b>{$protocol}</b> ({$networkType})";
        if (!empty(trim($modemName))) {
            $lines[] = "🏷️ Модификация: <b>{$modemName}</b>";
        }
        $lines[] = "🆔 UUID: <code>{$deviceId}</code>";

        return implode("\n", $lines);
    }

    public function buildDiagBatteryReport(array $config, DeviceDTO $device): string
    {
        $deviceId = $device->deviceId;
        $deviceSerial = $device->serialNumber ?? '—';
        $addr = $device->address ?: $device->name;

        $latestBat = UnicBoard::getLatestBattery($config, $deviceId);
        $batStr = ($latestBat && isset($latestBat['value'])) ? number_format((float) $latestBat['value'], 2, '.', '') . ' V' : '—';

        $lines = [];
        $lines[] = "🔋 <b>Питание и батарея (Модем № {$deviceSerial})</b>";
        if (!empty($addr)) {
            $lines[] = "📍 <i>{$addr}</i>\n";
        } else {
            $lines[] = "";
        }
        $lines[] = "🔋 Напряжение батареи: <b>{$batStr}</b>";

        return implode("\n", $lines);
    }

    public function buildDiagTemperatureReport(array $config, DeviceDTO $device): string
    {
        $deviceId = $device->deviceId;
        $deviceSerial = $device->serialNumber ?? '—';
        $addr = $device->address ?: $device->name;

        $latestTemp = UnicBoard::getLatestTemperature($config, $deviceId);
        $tempStr = ($latestTemp && isset($latestTemp['value'])) ? round((float) $latestTemp['value'], 1) . ' °C' : '—';

        $lines = [];
        $lines[] = "🌡️ <b>Температура (Модем № {$deviceSerial})</b>";
        if (!empty($addr)) {
            $lines[] = "📍 <i>{$addr}</i>\n";
        } else {
            $lines[] = "";
        }
        $lines[] = "💨 Температура прибора: <b>{$tempStr}</b>";

        return implode("\n", $lines);
    }

    public function buildDiagClockReport(array $config, DeviceDTO $device): string
    {
        $timezone = $config['timezone'] ?? 'Europe/Minsk';
        $deviceId = $device->deviceId;
        $deviceSerial = $device->serialNumber ?? '—';
        $addr = $device->address ?: $device->name;

        $latestClock = UnicBoard::getLatestClock($config, $deviceId);

        $lines = [];
        $lines[] = "🕒 <b>Время и синхронизация (Модем № {$deviceSerial})</b>";
        if (!empty($addr)) {
            $lines[] = "📍 <i>{$addr}</i>\n";
        } else {
            $lines[] = "";
        }

        if ($latestClock && !empty($latestClock['device_clock'])) {
            $clockDateStr = MeterService::formatDate($latestClock['device_clock'], 'd.m.Y H:i:s', $timezone);
            $syncSec = isset($latestClock['out_of_sync_s']) ? (float) $latestClock['out_of_sync_s'] : 0.0;
            $syncType = $latestClock['out_of_sync_type'] ?? 'synced';
            $syncSign = $syncSec > 0 ? "+{$syncSec}" : "{$syncSec}";
            $syncStatus = match ($syncType) {
                'synced' => 'синхронизировано',
                'out_of_sync_warning' => 'предупреждение',
                'out_of_sync_critical' => 'критическое расхождение',
                default => $syncType,
            };
            $lines[] = "🕒 Внутренние часы: <b>{$clockDateStr}</b>";
            $lines[] = "⏱ Расхождение с сервером: <b>{$syncSign} сек</b> (<i>{$syncStatus}</i>)";
        } else {
            $lines[] = "🕒 Информация о часах временно недоступна.";
        }

        return implode("\n", $lines);
    }

    public function userMetersList(array $config, string $chatId): string
    {
        $meters = $this->userMeterRepo ? $this->userMeterRepo->getMetersByChatId($chatId) : Storage::getUserMeters($chatId);
        if (empty($meters)) {
            return "У вас пока нет сохраненных счетчиков.\n\nНажмите кнопку «➕ Добавить счетчик» внизу или отправьте 7-значный номер модема.";
        }

        $lines = [];
        $lines[] = "📋 <b>Ваши сохраненные счетчики:</b>\n";
        foreach ($meters as $serial => $data) {
            $addr = is_array($data) ? ($data['address'] ?? $data['name'] ?? "Счетчик {$serial}") : (string) $data;
            $prefix = (str_starts_with($addr, '📍') || str_starts_with($addr, '💧')) ? '' : '📍 ';
            $lines[] = "• {$prefix}<b>{$addr}</b> (№ <code>{$serial}</code>)";
        }
        $lines[] = "\nНажмите на кнопку с адресом внизу для просмотра показаний.";
        return implode("\n", $lines);
    }
}
