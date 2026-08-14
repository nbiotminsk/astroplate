<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\DTO\DeviceDTO;

class ReportService
{
    public static function buildReport(array $config, DeviceDTO $device): string
    {
        $name = $device->name;
        $deviceId = $device->deviceId;

        $lines = [];
        $lines[] = "\xF0\x9F\x93\xB1 <b>{$name}</b>";

        // Серийные номера и свежие показания счетчиков из /info
        $infoPayload = UnicBoard::getDeviceInfo($config, $deviceId);

        $channelSerials = [];
        $liveChannels = [];
        if ($infoPayload && !empty($infoPayload['device_channel'])) {
            foreach ($infoPayload['device_channel'] as $idx => $ch) {
                $chNum = $ch['serial_number'] ?? ($idx + 1);
                if (isset($ch['serial_number'])) {
                    $channelSerials[$chNum] = (string) $ch['serial_number'];
                }
                $liveChannels[$chNum] = $ch;
            }
        }

        // Показания по каналам (запрашиваем историю с запасом limit=50)
        $values = UnicBoard::getDeviceValues($config, $deviceId, 50);
        $channelsHistory = [];

        // Добавляем текущие (живые) каналы из /info
        foreach ($liveChannels as $chNum => $chData) {
            $channelsHistory[$chNum][] = $chData;
        }

        if (!empty($values['payload'])) {
            foreach ($values['payload'] as $v) {
                $ch = (int) ($v['channel_number'] ?? 1);
                $channelsHistory[$ch][] = $v;
            }
            foreach ($channelsHistory as $chNum => &$hList) {
                usort($hList, static function ($a, $b) {
                    $tA = MeterService::parseUtcTimestamp(MeterService::extractRecordDate($a));
                    $tB = MeterService::parseUtcTimestamp(MeterService::extractRecordDate($b));
                    return $tB - $tA;
                });
            }
            unset($hList);
            ksort($channelsHistory);
        }

        if (!empty($channelsHistory)) {
            $lines[] = "\xF0\x9F\x93\x8A <b>Текущие показания:</b>";
            $totalChannels = count($channelsHistory);

            foreach ($channelsHistory as $chNum => $history) {
                $latest = $history[0] ?? null;
                $prev = $history[1] ?? null;

                $rawVal = $latest ? MeterService::extractRecordValue($latest) : null;
                $lastVal = $rawVal !== null ? round($rawVal, 4) : null;
                $lastValDate = $latest ? MeterService::extractRecordDate($latest) : null;
                $dateStr = $lastValDate ? MeterService::formatDate($lastValDate, 'd.m.Y H:i', $config['timezone'] ?? 'Europe/Minsk') : '—';
                $valStr = $lastVal !== null ? (string) $lastVal : '—';

                // Обновляем кэш расхода при получении текущих показаний
                if ($lastVal !== null && $lastValDate !== null) {
                    MeterService::getMeterConsumptionInfo($config, $deviceId, (int)$chNum, $lastVal, $lastValDate, $history);
                }

                $meterSerial = $channelSerials[$chNum] ?? null;
                if ($meterSerial && strlen((string) $meterSerial) > 4) {
                    $meterLabel = "Счетчик № {$meterSerial}";
                } else {
                    $meterLabel = "Канал ";
                }

                $diffStr = '';
                if ($latest !== null && $prev !== null) {
                    $prevVal = MeterService::extractRecordValue($prev);
                    if ($rawVal !== null && $prevVal !== null) {
                        $diff = $rawVal - $prevVal;
                        $formattedDiff = ($diff > 0 ? '+' : '') . round($diff, 4);
                        $diffStr = " (<b>{$formattedDiff} m³</b>)";
                    }
                }

                $valWithUnit = $valStr !== '—' ? "{$valStr} m³" : '—';
                $prefix = $totalChannels > 1 ? "{$chNum}. " : "";
                $lines[] = "{$prefix}<b>{$meterLabel}</b>: <b>{$valWithUnit}</b>{$diffStr} (<i>{$dateStr}</i>)";
            }
        } else {
            $lines[] = "\xF0\x9F\x93\x8A Показания: нет данных";
        }

        // Температура
        $temp = UnicBoard::getTemperature($config, $deviceId, 1);
        if ($temp !== null) {
            $t = MeterService::extractRecordValue($temp) ?? $temp['value'] ?? null;
            $lines[] = "\xF0\x9F\x92\xA8 Температура: <b>" . ($t !== null ? $t : '—') . " °C</b>";
        } else {
            $lines[] = "\xF0\x9F\x92\xA8 Температура: нет данных";
        }

        // Батарея
        $bat = UnicBoard::getBattery($config, $deviceId, 1);
        if ($bat !== null) {
            $b = MeterService::extractRecordValue($bat) ?? $bat['value'] ?? null;
            $lines[] = "\xF0\x9F\x94\x8B Батарея: <b>" . ($b !== null ? $b : '—') . " V</b>";
        } else {
            $lines[] = "\xF0\x9F\x94\x8B Батарея: нет данных";
        }

        if (empty($channelsHistory) && $temp === null && $bat === null) {
            $lines[] = "\n\xE2\x9A\xA0\xEF\xB8\x8F Не удалось получить данные по устройству {$deviceId}.";
        }

        return implode("\n", $lines);
    }

    /** Архив за текущий месяц (от 1 числа до текущего дня) */
    public static function buildMonthReport(array $config, DeviceDTO $device): string
    {
        $name = $device->name;
        $deviceId = $device->deviceId;

        $firstDay = date('01.m.Y 00:00');
        $lastDay = date('d.m.Y H:i');

        $lines = [];
        $lines[] = "\xF0\x9F\x93\x85 <b>Архив за текущий месяц ({$name})</b>";
        $lines[] = "Период: <b>{$firstDay}</b> — <b>{$lastDay}</b>\n";

        $channelSerials = UnicBoard::getDeviceChannelsSerials($config, $deviceId);

        $startMonthTs = strtotime(date('Y-m-01 00:00:00'));
        $endMonthTs = time();

        // Запрашиваем только текущий месяц + свежие онлайн показания
        $values = UnicBoard::getDeviceValues($config, $deviceId, 100, date('Y-m-01'));
        $latestLive = UnicBoard::getDeviceValues($config, $deviceId, 1);

        $allPayloads = array_merge($values['payload'] ?? [], $latestLive['payload'] ?? []);
        $channelsMonthData = [];

        if (!empty($allPayloads)) {
            foreach ($allPayloads as $v) {
                $ch = (int) ($v['channel_number'] ?? 1);
                $valDate = MeterService::extractRecordDate($v);
                if ($valDate) {
                    $ts = MeterService::parseUtcTimestamp($valDate);
                    if ($ts >= $startMonthTs && $ts <= $endMonthTs) {
                        $channelsMonthData[$ch][] = $v;
                    }
                }
            }
            ksort($channelsMonthData);
        }

        if (!empty($channelsMonthData)) {
            $totalChannels = count($channelsMonthData);
            foreach ($channelsMonthData as $chNum => $records) {
                usort($records, static function($a, $b) {
                    return MeterService::parseUtcTimestamp(MeterService::extractRecordDate($b)) - MeterService::parseUtcTimestamp(MeterService::extractRecordDate($a));
                });

                $latestInMonth = reset($records);
                $earliestInMonth = end($records);

                $rawValEnd = MeterService::extractRecordValue($latestInMonth);
                $valEnd = $rawValEnd !== null ? round($rawValEnd, 4) : null;
                $dateEnd = MeterService::extractRecordDate($latestInMonth);
                $dateEndStr = $dateEnd ? MeterService::formatDate($dateEnd, 'd.m.Y H:i', $config['timezone'] ?? 'Europe/Minsk') : '—';

                $rawValStart = MeterService::extractRecordValue($earliestInMonth);
                $valStart = $rawValStart !== null ? round($rawValStart, 4) : null;
                $dateStart = MeterService::extractRecordDate($earliestInMonth);
                $dateStartStr = $dateStart ? MeterService::formatDate($dateStart, 'd.m.Y H:i', $config['timezone'] ?? 'Europe/Minsk') : '—';

                $meterSerial = $channelSerials[$chNum] ?? null;
                $meterLabel = $meterSerial ? "Счетчик № {$meterSerial}" : "Счетчик {$chNum}";
                $prefix = $totalChannels > 1 ? "{$chNum}. " : "";

                $valStartStr = $valStart !== null ? "{$valStart} m³" : '—';
                $valEndStr = $valEnd !== null ? "{$valEnd} m³" : '—';

                $lines[] = "<b>{$prefix}{$meterLabel}:</b>";
                $lines[] = "  • Нач. месяца ({$dateStartStr}): <b>{$valStartStr}</b>";
                $lines[] = "  • Кон. периода ({$dateEndStr}): <b>{$valEndStr}</b>";

                if ($valEnd !== null && $valStart !== null && is_numeric($valEnd) && is_numeric($valStart)) {
                    $monthConsumption = (float) $valEnd - (float) $valStart;
                    $formattedConsumption = ($monthConsumption >= 0 ? '+' : '') . round($monthConsumption, 4);
                    $lines[] = "  • 📊 <b>Расход за месяц: {$formattedConsumption} m³</b>";
                }
                
                // Получаем или обновляем данные о последнем расходе из кэша
                $lastCons = MeterService::getMeterConsumptionInfo($config, $deviceId, (int)$chNum, $valEnd, $dateEnd, $records);
                if ($lastCons && !empty($lastCons['last_change_date'])) {
                    $lines[] = "\n  ℹ️ Последний расход зафиксирован: " . MeterService::formatDate($lastCons['last_change_date'], 'd.m.Y', $config['timezone'] ?? 'Europe/Minsk') . " (на " . round((float) $lastCons['last_change_diff'], 4) . " m³)";
                } else {
                    $lines[] = "\n  ℹ️ Последний расход не обнаружен.";
                }
                
                $lines[] = "";
            }
        } else {
            $lines[] = "\xF0\x9F\x93\x8A В текущем месяце записей не найдено.";
        }

        return implode("\n", $lines);
    }

    public static function userMetersList(array $config, string $chatId): string
    {
        $meters = Storage::getUserMeters($chatId);
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

    public static function devicesList(array $config): string
    {
        $lines = [];
        $lines[] = "\xF0\x9F\x9A\x80 <b>Доступные устройства:</b>";
        foreach ($config['devices'] as $id => $info) {
            $lines[] = "<code>{$id}</code> — {$info['name']}";
        }
        $lines[] = "\nВведите числовой ID устройства (серийный номер), чтобы получить данные.";
        return implode("\n", $lines);
    }
}
