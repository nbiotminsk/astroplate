<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\DTO\ChannelReadingDTO;
use TelegramBot\DTO\DeviceDTO;
use TelegramBot\DTO\HistoricalValueDTO;
use TelegramBot\DTO\MeterReadingDTO;
use TelegramBot\Repository\DeviceRepositoryInterface;
use TelegramBot\Repository\MeterCacheRepositoryInterface;

class MeterService
{
    /** Установи в true для отключения кэша (тестирование) */
    public static bool $disableCache = false;

    public function __construct(
        private ?DeviceRepositoryInterface $deviceRepo = null,
        private ?MeterCacheRepositoryInterface $cacheRepo = null
    ) {}

    /**
     * Определяет, является ли прибор умным счетчиком модели Fluo
     */
    public static function isFluoDevice(?array $infoPayload, ?DeviceDTO $device = null): bool
    {
        if ($infoPayload && !empty($infoPayload['device_modification'])) {
            $mod = $infoPayload['device_modification'];
            $modType = $mod['device_modification_type'] ?? [];
            $sysName = mb_strtolower((string) ($modType['sys_name'] ?? ''), 'UTF-8');
            $nameRu = mb_strtolower((string) ($modType['name_ru'] ?? ''), 'UTF-8');
            $nameEn = mb_strtolower((string) ($modType['name_en'] ?? ''), 'UTF-8');
            $modName = mb_strtolower((string) ($mod['name'] ?? ''), 'UTF-8');

            if (
                str_contains($sysName, 'fluo') ||
                str_contains($nameRu, 'fluo') ||
                str_contains($nameEn, 'fluo') ||
                $modName === 'mm230'
            ) {
                return true;
            }
        }

        if ($device !== null) {
            $devName = mb_strtolower($device->name, 'UTF-8');
            if (str_contains($devName, 'fluo')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Извлекает текущие показания каналов из полезной нагрузки /info
     *
     * @return ChannelReadingDTO[] Массив ChannelReadingDTO с ключами channel_number
     */
    public static function extractCurrentReadingsFromDeviceInfo(?array $infoPayload): array
    {
        if (!$infoPayload || empty($infoPayload['device_channel']) || !is_array($infoPayload['device_channel'])) {
            return [];
        }

        $readings = [];
        foreach ($infoPayload['device_channel'] as $idx => $ch) {
            if (!is_array($ch)) {
                continue;
            }

            $chNum = isset($ch['serial_number']) && is_numeric($ch['serial_number'])
                ? (int) $ch['serial_number']
                : ($idx + 1);

            $lastVal = null;
            $lastValDate = null;
            $unitMultiplier = 1.0;
            $valueMultiplier = 1.0;

            if (!empty($ch['device_meter']) && is_array($ch['device_meter'])) {
                $meter = $ch['device_meter'][0] ?? null;
                if (is_array($meter)) {
                    if (isset($meter['last_value']) && is_numeric($meter['last_value'])) {
                        $lastVal = (float) $meter['last_value'];
                    }
                    if (!empty($meter['last_value_date']) && is_string($meter['last_value_date'])) {
                        $lastValDate = $meter['last_value_date'];
                    }
                    if (isset($meter['unit_multiplier']) && is_numeric($meter['unit_multiplier'])) {
                        $unitMultiplier = (float) $meter['unit_multiplier'];
                    }
                    if (isset($meter['value_multiplier']) && is_numeric($meter['value_multiplier'])) {
                        $valueMultiplier = (float) $meter['value_multiplier'];
                    }
                }
            }

            $inactivityLimit = isset($ch['inactivity_limit']) && is_numeric($ch['inactivity_limit'])
                ? (int) $ch['inactivity_limit']
                : null;

            $lastDateEventNoData = !empty($ch['last_date_event_no_data']) && is_string($ch['last_date_event_no_data'])
                ? $ch['last_date_event_no_data']
                : null;

            // Канал включается в текущие онлайн-показания только при наличии валидного last_value
            if ($lastVal !== null) {
                $readings[$chNum] = new ChannelReadingDTO(
                    channelNumber: $chNum,
                    lastValue: $lastVal,
                    lastValueDate: $lastValDate,
                    unitMultiplier: $unitMultiplier,
                    valueMultiplier: $valueMultiplier,
                    inactivityLimit: $inactivityLimit,
                    lastDateEventNoData: $lastDateEventNoData
                );
            }
        }

        ksort($readings);
        return $readings;
    }

    /**
     * Извлекает исторические записи из полезной нагрузки /values
     *
     * @return HistoricalValueDTO[]
     */
    public static function extractHistoricalRecordsFromValues(array $valuesPayload): array
    {
        $records = [];
        foreach ($valuesPayload as $v) {
            if (!is_array($v)) {
                continue;
            }

            if (!isset($v['channel_number']) || !is_numeric($v['channel_number'])
                || !isset($v['value']) || !is_numeric($v['value'])
                || empty($v['date']) || !is_string($v['date'])) {
                continue;
            }

            $chNum = (int) $v['channel_number'];
            $val = (float) $v['value'];
            $date = $v['date'];

            $records[] = new HistoricalValueDTO(
                channelNumber: $chNum,
                date: $date,
                value: $val,
                valueRaw: isset($v['value_raw']) && is_numeric($v['value_raw']) ? (float) $v['value_raw'] : null,
                lastValue: isset($v['last_value']) && is_numeric($v['last_value']) ? (float) $v['last_value'] : null,
                lastValueDate: !empty($v['last_value_date']) && is_string($v['last_value_date']) ? $v['last_value_date'] : null,
                // Неизвестный тип не приравниваем к физическому DEVICE_DATA.
                valueType: isset($v['value_type']) && is_string($v['value_type']) ? $v['value_type'] : 'UNKNOWN',
                journalDataType: (string) ($v['journal_data_type'] ?? 'CURRENT'),
                kind: (string) ($v['kind'] ?? 'COMMON_CONSUMED'),
                meterId: (string) ($v['meter_id'] ?? ''),
                deviceId: (string) ($v['device_id'] ?? ''),
                tariffNumber: (int) ($v['tariff_number'] ?? -1),
                dateCreated: !empty($v['date_created']) && is_string($v['date_created']) ? $v['date_created'] : null
            );
        }

        return $records;
    }

    /**
     * Только DEVICE_DATA считается физическим показанием и может участвовать в расходе.
     */
    public static function isPhysicalHistoricalReading(HistoricalValueDTO $record): bool
    {
        return $record->valueType === 'DEVICE_DATA';
    }

    /**
     * Автоматически получает первичное/начальное показание с API прибора и сохраняет в registered_devices.json
     */
    public function fetchAndSaveInitialValues(array $config, string $serial, string $deviceId, array $explicitInitial = []): array
    {
        $customDevices = $this->deviceRepo ? $this->deviceRepo->loadAll() : Storage::loadRegisteredDevices();
        $existing = $customDevices[(int) $serial]['initial_values'] ?? [];

        if (!empty($explicitInitial)) {
            $initialValues = $explicitInitial;
        } elseif (!empty($existing)) {
            return $existing;
        } else {
            $infoResp = UnicBoard::getDeviceInfo($config, $deviceId);
            $readings = self::extractCurrentReadingsFromDeviceInfo($infoResp['payload'] ?? null);

            $initialValues = [];
            foreach ($readings as $chNum => $reading) {
                if ($reading->hasReading()) {
                    $initialValues[(string) $chNum] = $reading->lastValue;
                }
            }
        }

        if (!empty($initialValues)) {
            $name = $customDevices[(int) $serial]['name'] ?? "Устройство {$serial}";
            if ($this->deviceRepo) {
                $this->deviceRepo->registerDevice((string) $serial, $deviceId, $name, $initialValues);
            } else {
                if (!isset($customDevices[(int) $serial])) {
                    $customDevices[(int) $serial] = [
                        'name' => $name,
                        'device_id' => $deviceId,
                    ];
                }
                $customDevices[(int) $serial]['initial_values'] = $initialValues;
                Storage::saveRegisteredDevices($customDevices);
            }
        }

        return $initialValues;
    }

    /**
     * @return MeterReadingDTO[]
     */
    public static function extractChannelRecords(array $payload, int $chNum): array
    {
        $records = [];
        foreach (self::extractHistoricalRecordsFromValues($payload) as $record) {
            if ($record->channelNumber === $chNum && self::isPhysicalHistoricalReading($record)) {
                $records[] = new MeterReadingDTO($record->value, $record->date, $record->channelNumber);
            }
        }

        return $records;
    }

    /**
     * Преобразует только подтверждённые исторические показания в формат расчёта расхода.
     * INTERPOLATED_LINEAR и неизвестные типы намеренно исключаются.
     *
     * @return array<int, array{val: float, date: string}>
     */
    private static function recordsForConsumption(array $records): array
    {
        $parsed = [];
        foreach ($records as $record) {
            if ($record instanceof HistoricalValueDTO && self::isPhysicalHistoricalReading($record)) {
                $parsed[] = ['val' => $record->value, 'date' => $record->date];
            }
        }

        return $parsed;
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
    public function getMeterConsumptionInfo(
        array $config,
        string $deviceId,
        int $chNum,
        ?float $currentVal,
        ?string $currentDate,
        array $monthRecords = [],
        bool $fetchDeepHistoryIfMissing = false
    ): array {
        $cache = $this->cacheRepo ? $this->cacheRepo->loadCache() : Storage::loadMeterCache();
        $devCache = self::$disableCache ? null : ($cache[$deviceId]['channels'][$chNum] ?? null);

        if ($devCache !== null) {
            $lastVal = isset($devCache['last_value']) && is_numeric($devCache['last_value']) ? (float) $devCache['last_value'] : null;

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
                    if ($this->cacheRepo) {
                        $this->cacheRepo->saveCache($cache);
                    } else {
                        Storage::saveMeterCache($cache);
                    }
                }
                return $devCache;
            }

            // 2. Если дата расхода пуста, пробуем заполнить из записей текущего месяца
            if (empty($devCache['last_change_date']) && !empty($monthRecords)) {
                $parsed = self::recordsForConsumption($monthRecords);
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
                    if ($this->cacheRepo) {
                        $this->cacheRepo->saveCache($cache);
                    } else {
                        Storage::saveMeterCache($cache);
                    }
                }
            }

            return $devCache;
        }

        // Кэш отсутствует — строим историю
        $records = self::recordsForConsumption($monthRecords);

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

        // Если в текущем месяце дата смены показаний не найдена и разрешен глубокий опрос — разово опрашиваем историю API (-1 год)
        if ($changeDate === null && $fetchDeepHistoryIfMissing) {
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
            if ($this->cacheRepo) {
                $this->cacheRepo->saveCache($cache);
            } else {
                Storage::saveMeterCache($cache);
            }
        }

        return $info;
    }

    public static function calculateDisplayValue(float $currentApiVal, ?float $userInitial, ?float $baseApiVal): float
    {
        if ($userInitial === null) {
            return $currentApiVal;
        }
        if ($baseApiVal === null) {
            return $userInitial;
        }
        $delta = max(0.0, $currentApiVal - $baseApiVal);

        return $userInitial + $delta;
    }

    public function deviceLookup(array $config, string $input): ?DeviceDTO
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $cleanInput = trim(preg_replace('/^[📍💧\s]+/u', '', $input));
        if (preg_match('/\((\d+)\)$/', $cleanInput, $matches)) {
            $cleanInput = $matches[1];
        }

        // 1. Проверяем локальный конфиг config.php
        $devices = $config['devices'] ?? [];
        if (isset($devices[(int) $cleanInput])) {
            $dev = $devices[(int) $cleanInput];
            return DeviceDTO::fromArray($dev, (string) $cleanInput);
        }

        foreach ($devices as $id => $info) {
            if (
                mb_strtolower($info['name'] ?? '', 'UTF-8') === mb_strtolower($cleanInput, 'UTF-8') ||
                mb_strtolower($info['address'] ?? '', 'UTF-8') === mb_strtolower($cleanInput, 'UTF-8') ||
                mb_strtolower($info['name'] ?? '', 'UTF-8') === mb_strtolower($input, 'UTF-8') ||
                mb_strtolower($info['address'] ?? '', 'UTF-8') === mb_strtolower($input, 'UTF-8')
            ) {
                return DeviceDTO::fromArray($info, (string) $id);
            }
            if (($info['device_id'] ?? null) === $cleanInput || ($info['device_id'] ?? null) === $input) {
                return DeviceDTO::fromArray($info, (string) $id);
            }
        }

        // 2. Проверяем пользовательское динамическое хранилище registered_devices.json
        $customDevices = $this->deviceRepo ? $this->deviceRepo->loadAll() : Storage::loadRegisteredDevices();
        if (isset($customDevices[(int) $cleanInput])) {
            $dev = $customDevices[(int) $cleanInput];
            return DeviceDTO::fromArray($dev, (string) $cleanInput);
        }

        foreach ($customDevices as $id => $info) {
            if (
                mb_strtolower($info['name'] ?? '', 'UTF-8') === mb_strtolower($cleanInput, 'UTF-8') ||
                mb_strtolower($info['address'] ?? '', 'UTF-8') === mb_strtolower($cleanInput, 'UTF-8') ||
                mb_strtolower($info['name'] ?? '', 'UTF-8') === mb_strtolower($input, 'UTF-8') ||
                mb_strtolower($info['address'] ?? '', 'UTF-8') === mb_strtolower($input, 'UTF-8')
            ) {
                return DeviceDTO::fromArray($info, (string) $id);
            }
            if (($info['device_id'] ?? null) === $cleanInput || ($info['device_id'] ?? null) === $input) {
                return DeviceDTO::fromArray($info, (string) $id);
            }
        }

        // 3. Проверяем удаленный UnicBoard API по серийному номеру / MAC / ID
        if (ctype_digit($cleanInput) || preg_match('/^[0-9a-f-]{36}$/i', $cleanInput)) {
            $allRemote = UnicBoard::getAllDevices($config, 100);
            if (($allRemote['ok'] ?? false) && !empty($allRemote['payload'])) {
                foreach ($allRemote['payload'] as $item) {
                    $mfgSerial = (string) ($item['manufacturer_serial_number'] ?? '');
                    $mac = (string) ($item['data_gateway_network_device']['mac'] ?? '');
                    $devId = (string) ($item['id'] ?? '');

                    if ($mfgSerial === $cleanInput || $mac === $cleanInput || $devId === $cleanInput) {
                        $devName = $item['device_modification']['name'] ?? $item['device_modification']['device_modification_type']['name_ru'] ?? "Устройство {$mfgSerial}";
                        return new DeviceDTO(
                            deviceId: $devId,
                            serialNumber: $mfgSerial !== '' ? $mfgSerial : $cleanInput,
                            name: $devName
                        );
                    }
                }
            }
        }

        return null;
    }
}
