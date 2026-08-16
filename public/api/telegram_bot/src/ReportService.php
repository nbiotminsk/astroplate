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
        $name = $device->name;
        $deviceId = $device->deviceId;
        $timezone = $config['timezone'] ?? 'Europe/Minsk';

        $lines = [];
        $lines[] = "📱 <b>{$name}</b>";

        // 1. Текущие показания получаем ПЕРВИЧНО из GET /api/v1/devices/{device_id}/info
        $infoResp = UnicBoard::getDeviceInfo($config, $deviceId);
        $infoPayload = $infoResp['payload'] ?? null;
        $currentReadings = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayload);

        // 2. Историю для расчета расхода запрашиваем через POST /api/v1/devices/values (опционально)
        $valuesResp = UnicBoard::getDeviceValues($config, $deviceId, 50);
        $historyRecords = MeterService::extractHistoricalRecordsFromValues($valuesResp['payload'] ?? []);

        $historyByChannel = [];
        foreach ($historyRecords as $rec) {
            $historyByChannel[$rec->channelNumber][] = $rec;
        }
        foreach ($historyByChannel as $chNum => &$hList) {
            usort($hList, static function (HistoricalValueDTO $a, HistoricalValueDTO $b) {
                return MeterService::parseUtcTimestamp($b->date) - MeterService::parseUtcTimestamp($a->date);
            });
        }
        unset($hList);

        // 3. Формируем блок показаний по каналам
        if (!empty($currentReadings)) {
            $lines[] = "📊 <b>Текущие показания:</b>";
            $totalChannels = count($currentReadings);
            $latestDate = null;
            $inactivityNotes = [];

            $isFluo = MeterService::isFluoDevice($infoPayload, $device);
            $deviceSerial = $device->serialNumber !== '' ? $device->serialNumber : $deviceId;

            foreach ($currentReadings as $chNum => $reading) {
                $lastVal = $reading->lastValue;
                $lastValDate = $reading->lastValueDate;
                if ($lastValDate !== null && ($latestDate === null || $lastValDate > $latestDate)) {
                    $latestDate = $lastValDate;
                }

                $valStr = $lastVal !== null ? (string) round($lastVal, 4) : '—';
                $valWithUnit = $valStr !== '—' ? "{$valStr} m³" : '—';

                // Обновляем кэш расхода
                if ($lastVal !== null && $lastValDate !== null) {
                    $svc = $this->meterService ?? new MeterService();
                    $svc->getMeterConsumptionInfo($config, $deviceId, $chNum, $lastVal, $lastValDate, $historyByChannel[$chNum] ?? []);
                }

                // Разница с предыдущим значением из истории
                $diffStr = '';
                $channelHistory = $historyByChannel[$chNum] ?? [];
                if ($lastVal !== null && !empty($channelHistory)) {
                    // Ищем ближайшую предыдущую запись с другим значением
                    foreach ($channelHistory as $hRec) {
                        if (round($hRec->value, 4) != round($lastVal, 4)) {
                            $diff = $lastVal - $hRec->value;
                            $formattedDiff = ($diff > 0 ? '+' : '') . round($diff, 4);
                            $diffStr = " (<b>{$formattedDiff} m³</b>)";
                            break;
                        }
                    }
                }

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

                if ($reading->isInactive() && $reading->lastDateEventNoData !== null) {
                    $inactivityDate = MeterService::formatDate($reading->lastDateEventNoData, 'd.m.Y', $timezone);
                    $inactivityNotes[] = "<i>(нет данных с {$inactivityDate})</i>";
                }

                $lines[] = "{$prefix}<b>{$meterLabel}</b>: <b>{$valWithUnit}</b>{$diffStr}";
            }

            // Единый вывод даты и времени для прибора
            $dateStr = $latestDate ? MeterService::formatDate($latestDate, 'd.m.Y H:i', $timezone) : '—';
            $inactivityStr = !empty($inactivityNotes) ? ' ' . implode(', ', array_unique($inactivityNotes)) : '';
            $lines[] = "🕒 Дата: <b>({$dateStr})</b>{$inactivityStr}";
        } elseif (!empty($historyByChannel)) {
            // Фолбэк: если /info вернул пустые каналы, но в /values есть история
            $lines[] = "📊 <b>Последние сохраненные показания:</b>";
            $totalChannels = count($historyByChannel);
            ksort($historyByChannel);

            $isFluo = MeterService::isFluoDevice($infoPayload, $device);
            $deviceSerial = $device->serialNumber !== '' ? $device->serialNumber : $deviceId;
            $latestDate = null;

            foreach ($historyByChannel as $chNum => $history) {
                $latest = $history[0] ?? null;
                $val = $latest ? $latest->value : null;
                if ($latest && $latest->date && ($latestDate === null || $latest->date > $latestDate)) {
                    $latestDate = $latest->date;
                }
                $valStr = $val !== null ? (string) round($val, 4) : '—';
                $valWithUnit = $valStr !== '—' ? "{$valStr} m³" : '—';

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

                $lines[] = "{$prefix}<b>{$meterLabel}</b>: <b>{$valWithUnit}</b>";
            }

            $dateStr = $latestDate ? MeterService::formatDate($latestDate, 'd.m.Y H:i', $timezone) : '—';
            $lines[] = "🕒 Дата: <b>({$dateStr})</b>";
        } else {
            $lines[] = "📊 Показания: нет данных";
        }

        // 4. Температура (опциональная телеметрия)
        $temp = UnicBoard::getLatestTemperature($config, $deviceId);
        if ($temp !== null && isset($temp['value']) && is_numeric($temp['value'])) {
            $lines[] = "💨 Температура: <b>" . round((float) $temp['value'], 1) . " °C</b>";
        } else {
            $lines[] = "💨 Температура: нет данных";
        }

        // 5. Батарея (опциональная телеметрия)
        $bat = UnicBoard::getLatestBattery($config, $deviceId);
        if ($bat !== null && isset($bat['value']) && is_numeric($bat['value'])) {
            $lines[] = "🔋 Батарея: <b>" . round((float) $bat['value'], 2) . " V</b>";
        } else {
            $lines[] = "🔋 Батарея: нет данных";
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
        $historyRecords = MeterService::extractHistoricalRecordsFromValues($valuesResp['payload'] ?? []);

        // 2. Запрашиваем текущие онлайн показания из /info
        $infoResp = UnicBoard::getDeviceInfo($config, $deviceId);
        $infoPayload = $infoResp['payload'] ?? null;
        $currentReadings = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayload);

        $channelsMonthData = [];
        foreach ($historyRecords as $rec) {
            $ts = MeterService::parseUtcTimestamp($rec->date);
            if ($ts >= $startMonthTs && $ts <= $endMonthTs) {
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

    public function userMetersList(array $config, string $chatId): string
    {
        $meters = $this->userMeterRepo ? $this->userMeterRepo->getMetersByChatId($chatId) : Storage::getUserMeters($chatId);
        if (empty($meters)) {
            return "У вас пока нет сохраненных счетчиков.\n\nВведите серийный номер прибора или команду:\n<code>/add 8527038</code>";
        }

        $lines = [];
        $lines[] = "📋 <b>Ваши сохраненные счетчики:</b>\n";
        foreach ($meters as $serial => $data) {
            $name = is_array($data) ? ($data['name'] ?? "Счетчик {$serial}") : (string) $data;
            $lines[] = "• <b>{$name}</b> (серийный №: <code>{$serial}</code>)";
        }
        $lines[] = "\nНажмите на кнопку с именем счетчика внизу или введите его серийный номер.";
        return implode("\n", $lines);
    }
}
