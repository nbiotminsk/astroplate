<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\DTO\DeviceDTO;
use TelegramBot\DTO\MeterReadingDTO;

class MeterService
{
    /** Установи в true для отключения кэша (тестирование) */
    public static bool $disableCache = false;

    /**
     * Автоматически получает первичное/начальное показание с API прибора и сохраняет в registered_devices.json
     */
    public static function fetchAndSaveInitialValues(array $config, string $serial, string $deviceId, array $explicitInitial = []): array
    {
        $customDevices = Storage::loadRegisteredDevices();
        $existing = $customDevices[(int) $serial]['initial_values'] ?? [];

        if (!empty($explicitInitial)) {
            $initialValues = $explicitInitial;
        } elseif (!empty($existing)) {
            return $existing;
        } else {
            // Опрашиваем API на предмет начальных показаний / стартовых значений
            $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/info';
            [$code, $resp] = Telegram::httpGet($url, UnicBoard::unicboardHeaders($config));

            $initialValues = [];
            if ($code === 200 && !empty($resp['payload']['device_channel'])) {
                foreach ($resp['payload']['device_channel'] as $idx => $ch) {
                    $chNum = $ch['serial_number'] ?? ($idx + 1);
                    $meter = $ch['device_meter'][0] ?? null;
                    if ($meter && isset($meter['last_value'])) {
                        $initialValues[(string) $chNum] = (float) $meter['last_value'];
                    }
                }
            }
        }

        if (!empty($initialValues)) {
            if (!isset($customDevices[(int) $serial])) {
                $customDevices[(int) $serial] = [
                    'name' => "Устройство {$serial}",
                    'device_id' => $deviceId,
                ];
            }
            $customDevices[(int) $serial]['initial_values'] = $initialValues;
            Storage::saveRegisteredDevices($customDevices);
        }

        return $initialValues;
    }

    /**
     * @return MeterReadingDTO[]
     */
    public static function extractChannelRecords(array $payload, int $chNum): array
    {
        $records = [];
        foreach ($payload as $v) {
            $channelsList = [];
            if (isset($v['device_channel']) && is_array($v['device_channel'])) {
                $channelsList = $v['device_channel'];
            } elseif (isset($v['channels']) && is_array($v['channels'])) {
                $channelsList = $v['channels'];
            }

            if (!empty($channelsList)) {
                foreach ($channelsList as $idx => $chData) {
                    $num = $chData['channel_number'] ?? ($idx + 1);
                    if ((int) $num === (int) $chNum && is_array($chData)) {
                        $combined = array_merge($v, $chData);
                        $val = self::extractRecordValue($combined);
                        $date = self::extractRecordDate($combined);
                        if ($val !== null && $date !== null) {
                            $records[] = new MeterReadingDTO($val, $date, $chNum);
                        }
                    }
                }
            } else {
                $chData = $v['device_meter'] ?? $v;
                $num = $chData['channel_number'] ?? $v['channel_number'] ?? 1;
                if ((int) $num === (int) $chNum) {
                    $val = self::extractRecordValue($chData);
                    $date = self::extractRecordDate($chData);
                    if ($val !== null && $date !== null) {
                        $records[] = new MeterReadingDTO($val, $date, (int) $num);
                    }
                }
            }
        }
        return $records;
    }

    public static function extractRecordValue(array $rec): ?float
    {
        foreach (['value', 'meter_reading', 'meter_value', 'last_value', 'pulse', 'counter'] as $key) {
            if (isset($rec[$key]) && is_numeric($rec[$key])) {
                return (float) $rec[$key];
            }
        }
        foreach (['device_meter', 'channels', 'device_channel'] as $arrKey) {
            if (isset($rec[$arrKey]) && is_array($rec[$arrKey])) {
                foreach ($rec[$arrKey] as $c) {
                    if (is_array($c)) {
                        $val = self::extractRecordValue($c);
                        if ($val !== null) {
                            return $val;
                        }
                    }
                }
            }
        }
        return null;
    }

    public static function extractRecordDate(array $rec): ?string
    {
        foreach (['date', 'last_value_date', 'created_at', 'timestamp', 'time'] as $key) {
            if (!empty($rec[$key]) && is_string($rec[$key])) {
                return $rec[$key];
            }
        }
        foreach (['device_meter', 'channels', 'device_channel'] as $arrKey) {
            if (isset($rec[$arrKey]) && is_array($rec[$arrKey])) {
                foreach ($rec[$arrKey] as $c) {
                    if (is_array($c)) {
                        $val = self::extractRecordDate($c);
                        if ($val !== null) {
                            return $val;
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Парсит дату от API (в UTC) и возвращает unix timestamp.
     */
    public static function parseUtcTimestamp(?string $dateStr): int
    {
        if (empty($dateStr)) {
            return 0;
        }
        try {
            if (preg_match('/[Zz]|[\+\-]\d{2}:?\d{2}$/', $dateStr)) {
                $dt = new \DateTimeImmutable($dateStr);
            } else {
                $dt = new \DateTimeImmutable($dateStr, new \DateTimeZone('UTC'));
            }
            return $dt->getTimestamp();
        } catch (\Throwable) {
            $ts = strtotime($dateStr);
            return $ts !== false ? $ts : 0;
        }
    }

    /**
     * Форматирует дату от API (в UTC) в целевой часовой пояс (по умолчанию Europe/Minsk, UTC+3).
     */
    public static function formatDate(?string $dateStr, string $format = 'd.m.Y H:i', string $targetTimezone = 'Europe/Minsk'): string
    {
        if (empty($dateStr)) {
            return '—';
        }
        try {
            if (preg_match('/[Zz]|[\+\-]\d{2}:?\d{2}$/', $dateStr)) {
                $dt = new \DateTimeImmutable($dateStr);
            } else {
                $dt = new \DateTimeImmutable($dateStr, new \DateTimeZone('UTC'));
            }
            return $dt->setTimezone(new \DateTimeZone($targetTimezone))->format($format);
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * Получает или обновляет кэшированные данные о последнем расходе счетчика
     */
    public static function getMeterConsumptionInfo(array $config, string $deviceId, int $chNum, ?float $currentVal, ?string $currentDate, array $monthRecords = []): array
    {
        $cache = Storage::loadMeterCache();
        $devCache = self::$disableCache ? null : ($cache[$deviceId]['channels'][$chNum] ?? null);

        if ($devCache !== null) {
            $lastVal = isset($devCache['last_value']) ? (float) $devCache['last_value'] : null;

            // 1. Если передано актуальное показание и оно выросло — обновляем расход в кэше
            if ($currentVal !== null && $lastVal !== null && round($currentVal, 4) > round($lastVal, 4)) {
                $diff = round($currentVal - $lastVal, 4);
                $devCache = [
                    'last_value' => $currentVal,
                    'last_change_date' => $currentDate,
                    'last_change_diff' => $diff,
                    'first_date' => $devCache['first_date'] ?? $currentDate,
                ];
                $cache[$deviceId]['channels'][$chNum] = $devCache;
                if (!self::$disableCache) {
                    Storage::saveMeterCache($cache);
                }
                return $devCache;
            }

            // 2. Если дата расхода пуста, пробуем заполнить из записей текущего месяца
            if (empty($devCache['last_change_date']) && !empty($monthRecords)) {
                $parsed = [];
                foreach ($monthRecords as $r) {
                    $v = is_array($r) ? self::extractRecordValue($r) : ($r instanceof MeterReadingDTO ? $r->val : null);
                    $d = is_array($r) ? self::extractRecordDate($r) : ($r instanceof MeterReadingDTO ? $r->date : null);
                    if ($v !== null && $d !== null) {
                        $parsed[] = ['val' => $v, 'date' => $d];
                    }
                }
                usort($parsed, static function($a, $b) {
                    return self::parseUtcTimestamp($a['date']) - self::parseUtcTimestamp($b['date']);
                });

                $prevV = null;
                foreach ($parsed as $p) {
                    if ($prevV === null) {
                        $prevV = $p['val'];
                    } elseif (round($p['val'], 4) != round($prevV, 4)) {
                        $devCache['last_change_date'] = $p['date'];
                        $devCache['last_change_diff'] = round(abs($p['val'] - $prevV), 4);
                        $prevV = $p['val'];
                    }
                }

                if (!empty($devCache['last_change_date']) && !self::$disableCache) {
                    $cache[$deviceId]['channels'][$chNum] = $devCache;
                    Storage::saveMeterCache($cache);
                }
            }

            return $devCache;
        }

        // Кэш отсутствует — строим историю
        $records = [];
        if (!empty($monthRecords)) {
            foreach ($monthRecords as $r) {
                $v = is_array($r) ? self::extractRecordValue($r) : ($r instanceof MeterReadingDTO ? $r->val : null);
                $d = is_array($r) ? self::extractRecordDate($r) : ($r instanceof MeterReadingDTO ? $r->date : null);
                if ($v !== null && $d !== null) {
                    $records[] = ['val' => $v, 'date' => $d];
                }
            }
        }

        $lastVal = null;
        $changeDate = null;
        $changeDiff = null;
        $firstDate = null;

        usort($records, static function($a, $b) {
            return self::parseUtcTimestamp($a['date']) - self::parseUtcTimestamp($b['date']);
        });

        foreach ($records as $r) {
            if ($firstDate === null) {
                $firstDate = $r['date'];
            }
            if ($lastVal === null) {
                $lastVal = $r['val'];
            } elseif (round($r['val'], 4) != round($lastVal, 4)) {
                $changeDate = $r['date'];
                $changeDiff = round(abs($r['val'] - $lastVal), 4);
                $lastVal = $r['val'];
            }
        }

        // Если в текущем месяце дата смены показаний не найдена — разово опрашиваем историю API (-1 год)
        if ($changeDate === null) {
            $history = UnicBoard::getDeviceValues($config, $deviceId, 500, date('Y-m-d', strtotime('-1 year')));
            if (!empty($history['payload'])) {
                $hRecords = self::extractChannelRecords($history['payload'], $chNum);
                usort($hRecords, static function($a, $b) {
                    return self::parseUtcTimestamp($a->date) - self::parseUtcTimestamp($b->date);
                });

                $hLastVal = null;
                foreach ($hRecords as $r) {
                    if ($firstDate === null) {
                        $firstDate = $r->date;
                    }
                    if ($hLastVal === null) {
                        $hLastVal = $r->val;
                    } elseif (round($r->val, 4) != round($hLastVal, 4)) {
                        $changeDate = $r->date;
                        $changeDiff = round(abs($r->val - $hLastVal), 4);
                        $hLastVal = $r->val;
                    }
                }
                if ($lastVal === null) {
                    $lastVal = $hLastVal;
                }
            }
        }

        $info = [
            'last_value' => $currentVal ?? $lastVal ?? 0.0,
            'last_change_date' => $changeDate,
            'last_change_diff' => $changeDiff,
            'first_date' => $firstDate,
        ];

        if (!self::$disableCache) {
            if (!isset($cache[$deviceId])) {
                $cache[$deviceId] = ['channels' => []];
            }
            $cache[$deviceId]['channels'][$chNum] = $info;
            Storage::saveMeterCache($cache);
        }

        return $info;
    }

    public static function deviceLookup(array $config, string $input): ?DeviceDTO
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // 1. Проверяем локальный конфиг config.php
        $devices = $config['devices'] ?? [];
        if (isset($devices[(int) $input])) {
            $dev = $devices[(int) $input];
            return DeviceDTO::fromArray($dev, (string) $input);
        }

        foreach ($devices as $id => $info) {
            if (mb_strtolower($info['name'] ?? '', 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
                return DeviceDTO::fromArray($info, (string) $id);
            }
        }

        // 2. Проверяем пользовательское динамическое хранилище registered_devices.json
        $customDevices = Storage::loadRegisteredDevices();
        if (isset($customDevices[(int) $input])) {
            $dev = $customDevices[(int) $input];
            return DeviceDTO::fromArray($dev, (string) $input);
        }

        foreach ($customDevices as $id => $info) {
            if (mb_strtolower($info['name'], 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
                return DeviceDTO::fromArray($info, (string) $id);
            }
        }

        // 3. Если не найден — пробуем динамически запросить список приборов через API
        $apiDevices = UnicBoard::getAllDevices($config);
        foreach ($apiDevices as $item) {
            $serial = (string) ($item['manufacturer_serial_number'] ?? '');
            $devId = $item['id'] ?? '';
            $name = $item['device_modification']['name'] ?? $item['device_manufacturer']['name'] ?? "Устройство {$serial}";

            if ($serial === $input || mb_strtolower($name, 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
                $initialValues = self::fetchAndSaveInitialValues($config, $serial, $devId);
                return new DeviceDTO(
                    deviceId: $devId,
                    serialNumber: $serial,
                    name: $name,
                    initialValues: $initialValues
                );
            }
        }

        return null;
    }
}
