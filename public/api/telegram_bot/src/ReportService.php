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
                    $meterLabel = "• Счетчик № {$meterNum}";
                } elseif (count($currentReadings) > 1) {
                    $meterLabel = "• Вход {$chNum}";
                } else {
                    $meterLabel = "• Счетчик № {$deviceSerial}";
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
                    $meterLabel = "• Счетчик № {$meterNum}";
                } else {
                    $meterLabel = "• Вход {$chNum}";
                }

                $lines[] = "{$meterLabel}: <b>{$valWithUnit}</b>";
            }

            $dateStr = $latestDate ? MeterService::formatDate($latestDate, 'd.m.Y H:i', $timezone) : '—';
            $lines[] = "\n🕒 {$dateStr}";
        } else {
            $lines[] = "• Показания: нет данных";
        }

        // 4. Температура и батарея
        $temp = UnicBoard::getLatestTemperature($config, $deviceId);
        $bat = UnicBoard::getLatestBattery($config, $deviceId);

        if ($temp !== null && isset($temp['value']) && is_numeric($temp['value'])) {
            $lines[] = "💨 Температура: " . round((float) $temp['value'], 1) . " °C";
        }
        if ($bat !== null && isset($bat['value']) && is_numeric($bat['value'])) {
            $lines[] = "🔋 Батарея: " . number_format((float) $bat['value'], 2, '.', '') . " V";
        }

        if (empty($currentReadings) && empty($historyRecords) && $temp === null && $bat === null) {
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

        if (!empty($channelsMonthData)) {
            $totalChannels = count($channelsMonthData);
            foreach ($channelsMonthData as $chNum => $records) {
                usort($records, static function (HistoricalValueDTO $a, HistoricalValueDTO $b) {
                    return MeterService::parseUtcTimestamp($b->date) - MeterService::parseUtcTimestamp($a->date);
                });

                $latestHistory = reset($records) ?: null;
                $earliestInMonth = end($records) ?: null;

                // Начало месяца
                $valStart = $earliestInMonth ? $earliestInMonth->value : null;
                $dateStart = $earliestInMonth ? $earliestInMonth->date : null;
                $dateStartStr = $dateStart ? MeterService::formatDate($dateStart, 'd.m.Y H:i', $timezone) : '—';

                // Конец периода: приоритет текущему показанию из /info, если оно новее
                $currentCh = $currentReadings[$chNum] ?? null;
                if ($currentCh && $currentCh->hasReading()) {
                    $valEnd = $currentCh->lastValue;
                    $dateEnd = $currentCh->lastValueDate;
                } elseif ($latestHistory) {
                    $valEnd = $latestHistory->value;
                    $dateEnd = $latestHistory->date;
                } else {
                    $valEnd = null;
                    $dateEnd = null;
                }
                $dateEndStr = $dateEnd ? MeterService::formatDate($dateEnd, 'd.m.Y H:i', $timezone) : '—';

                // Если начало месяца не зафиксировано в /values, используем начальное значение прибора
                if ($valStart === null && isset($device->initialValues[(string) $chNum])) {
                    $valStart = (float) $device->initialValues[(string) $chNum];
                    $dateStartStr = date('01.m.Y 00:00');
                } elseif ($valStart === null && $valEnd !== null) {
                    $valStart = $valEnd;
                    $dateStartStr = $dateEndStr;
                }

                $isFluo = MeterService::isFluoDevice($infoPayload, $device);
                $deviceSerial = $device->serialNumber !== '' ? $device->serialNumber : $deviceId;

                if ($isFluo) {
                    $meterLabel = "Счетчик Fluo № {$deviceSerial}";
                    $prefix = "";
                } elseif ($totalChannels > 1) {
                    $meterLabel = "Канал {$chNum}";
                    $prefix = "{$chNum}. ";
                } else {
                    $meterLabel = "Счетчик № {$deviceSerial}";
                    $prefix = "";
                }

                $valStartStr = $valStart !== null ? round($valStart, 4) . " m³" : '—';
                $valEndStr = $valEnd !== null ? round($valEnd, 4) . " m³" : '—';

                $lines[] = "<b>{$prefix}{$meterLabel}:</b>";
                $lines[] = "  • Нач. месяца ({$dateStartStr}): <b>{$valStartStr}</b>";
                $lines[] = "  • Кон. периода ({$dateEndStr}): <b>{$valEndStr}</b>";

                if ($valEnd !== null && $valStart !== null) {
                    $monthConsumption = $valEnd - $valStart;
                    $formattedConsumption = ($monthConsumption >= 0 ? '+' : '') . round($monthConsumption, 4);
                    $lines[] = "  • 📊 <b>Расход за месяц: {$formattedConsumption} m³</b>";
                }

                // Кэш расхода
                $svc = $this->meterService ?? new MeterService();
                $lastCons = $svc->getMeterConsumptionInfo($config, $deviceId, (int) $chNum, $valEnd, $dateEnd, $records);

                if ($lastCons && !empty($lastCons['last_change_date'])) {
                    $diffVal = isset($lastCons['last_change_diff']) ? round((float) $lastCons['last_change_diff'], 4) : 0.0;
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
        $timezone = $config['timezone'] ?? 'Europe/Minsk';
        $deviceId = $device->deviceId;
        $deviceSerial = $device->serialNumber ?? '—';
        $addr = $device->address ?: $device->name;

        $infoResp = UnicBoard::getDeviceInfo($config, $deviceId);
        $payload = $infoResp['payload'] ?? [];

        $lines = [];
        $lines[] = "⚙️ <b>Диагностика модема № {$deviceSerial}</b>";
        if (!empty($addr)) {
            $lines[] = "📍 <i>{$addr}</i>\n";
        } else {
            $lines[] = "";
        }

        $channels = $payload['device_channel'] ?? [];
        if (empty($channels)) {
            // Фолбэк 1: поиск прибора в общем списке
            $allDevices = UnicBoard::getAllDevices($config);
            foreach ($allDevices['payload'] ?? [] as $devItem) {
                if (($devItem['id'] ?? null) === $deviceId && !empty($devItem['device_channel'])) {
                    $payload = $devItem;
                    $channels = $devItem['device_channel'];
                    break;
                }
            }
        }

        if (empty($channels) && !empty($device->channels)) {
            // Фолбэк 2: локальная конфигурация каналов
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
                $unitMultiplier = isset($meterBilling['unit_multiplier']) && is_numeric($meterBilling['unit_multiplier']) ? (float) $meterBilling['unit_multiplier'] : 10.0;
                $valueMultiplier = isset($meterBilling['value_multiplier']) && is_numeric($meterBilling['value_multiplier']) ? (float) $meterBilling['value_multiplier'] : 1.0;

                // В UnicBoard базовый множитель 1 соответствует 10 литрам на импульс (0.01 м³)
                $litersPerPulse = $unitMultiplier * 10.0 * $valueMultiplier;
                $m3PerPulse = $litersPerPulse / 1000.0;
                $pulses = ($m3PerPulse > 0 && $lastVal !== null) ? (int) round($lastVal / $m3PerPulse) : 0;

                $chConfig = $device->channels[$chNum] ?? $device->channels[(string) $chNum] ?? null;
                $meterNum = $chConfig['meter_number'] ?? null;
                $meterLabel = $meterNum ? " (№ <code>{$meterNum}</code>)" : "";

                $formattedVal = $lastVal !== null ? number_format($lastVal, 2, '.', '') . ' m³' : '—';
                $dateStr = MeterService::formatDate($lastValDate, 'd.m.Y H:i', $timezone);
                $multiplierLiters = round($litersPerPulse, 3);

                $statusActive = in_array($chNum, $activeChannels, true) ? "✅ Активен" : "⏸ Отключен в боте";

                $lines[] = "<b>Вход {$chNum}{$meterLabel}</b> [{$statusActive}]:";
                $lines[] = "• Вес импульса (множитель): <b>{$multiplierLiters} л/имп</b> ({$m3PerPulse} м³/имп)";
                $lines[] = "• Число импульсов: <b>{$pulses} имп.</b>";
                $lines[] = "• Объём в базе: <b>{$formattedVal}</b>";
                $lines[] = "• Последний срез: {$dateStr}\n";
            }
        } else {
            $lines[] = "ℹ️ Информация по каналам не найдена.\n";
        }

        // Телеметрия (батарея, температура, часы, протокол)
        $latestBat = UnicBoard::getLatestBattery($config, $deviceId);
        $latestTemp = UnicBoard::getLatestTemperature($config, $deviceId);
        $latestClock = UnicBoard::getLatestClock($config, $deviceId);

        $batStr = ($latestBat && isset($latestBat['value'])) ? number_format((float) $latestBat['value'], 2, '.', '') . ' V' : '—';
        $tempStr = ($latestTemp && isset($latestTemp['value'])) ? round((float) $latestTemp['value']) . ' °C' : '—';

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
        } elseif (!empty($channels[0]['device_meter'][0]['last_value_date'])) {
            $rawDate = $channels[0]['device_meter'][0]['last_value_date'];
            $clockDateStr = MeterService::formatDate($rawDate, 'd.m.Y H:i', $timezone);
            $lines[] = "🕒 Время модема: <b>{$clockDateStr}</b> (<i>синхронизировано</i>)";
            $lines[] = "⏱ Расхождение с сервером: <b>0 сек</b> (<i>норма</i>)";
        }

        $protocol = $payload['data_gateway_network_device']['protocol']['name'] ?? 'SMP_M';
        $networkType = $payload['data_gateway_network_device']['network']['type_network'] ?? 'input';
        $modemName = ($payload['device_modification']['device_modification_type']['name_ru'] ?? 'Модем') . ' ' . ($payload['device_modification']['name'] ?? '');

        $lines[] = "💨 Температура: <b>{$tempStr}</b>";
        $lines[] = "🔋 Батарея: <b>{$batStr}</b>";
        $lines[] = "📡 Протокол передачи: <b>{$protocol}</b> ({$networkType})";
        if (!empty(trim($modemName))) {
            $lines[] = "🏷️ Модификация: <b>{$modemName}</b>";
        }
        $lines[] = "🆔 UUID: <code>{$deviceId}</code>";

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
