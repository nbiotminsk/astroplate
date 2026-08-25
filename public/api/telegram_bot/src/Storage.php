<?php

declare(strict_types=1);

namespace TelegramBot;

class Storage
{
    public static function customDevicesFile(): string
    {
        return __DIR__ . '/../storage/registered_devices.json';
    }

    public static function userStorageFile(): string
    {
        return __DIR__ . '/../storage/user_meters.json';
    }

    public static function meterCacheFile(): string
    {
        return __DIR__ . '/../storage/meter_cache.json';
    }

    public static function logFile(): string
    {
        return __DIR__ . '/../storage/bot.log';
    }

    public static function log(string $message, array $context = []): void
    {
        $file = self::logFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $time = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        @file_put_contents($file, "[{$time}] {$message}{$contextStr}\n", FILE_APPEND | LOCK_EX);
    }

    public static function atomicWriteJson(string $filePath, array $data): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmpFile = $filePath . '.tmp.' . getmypid() . '_' . microtime(true);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($tmpFile, $json) !== false) {
            rename($tmpFile, $filePath);
        }
    }

    public static function loadJsonWithLock(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        $fp = @fopen($filePath, 'rb');
        if (!$fp) {
            return [];
        }
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return json_decode((string) $content, true) ?: [];
    }

    public static function userStatesFile(): string
    {
        return __DIR__ . '/../storage/user_states.json';
    }

    public static function loadUserStates(): array
    {
        return self::loadJsonWithLock(self::userStatesFile());
    }

    public static function saveUserStates(array $states): void
    {
        self::atomicWriteJson(self::userStatesFile(), $states);
    }

    public static function getUserState(string $chatId): ?array
    {
        $all = self::loadUserStates();
        return $all[$chatId] ?? null;
    }

    public static function setUserState(string $chatId, array $state): void
    {
        $all = self::loadUserStates();
        $all[$chatId] = $state;
        self::saveUserStates($all);
    }

    public static function clearUserState(string $chatId): void
    {
        $all = self::loadUserStates();
        if (isset($all[$chatId])) {
            unset($all[$chatId]);
            self::saveUserStates($all);
        }
    }

    public static function loadRegisteredDevices(): array
    {
        return self::loadJsonWithLock(self::customDevicesFile());
    }

    public static function saveRegisteredDevices(array $devices): void
    {
        self::atomicWriteJson(self::customDevicesFile(), $devices);
    }

    public static function registerCustomDevice(
        string $serial,
        string $uuid,
        string $name,
        array $initialValues = [],
        ?string $address = null,
        ?array $activeChannels = null,
        ?array $channels = null
    ): void {
        $devices = self::loadRegisteredDevices();
        $key = (int) $serial;
        $devData = $devices[$key] ?? [];
        $devData['name'] = $name;
        $devData['device_id'] = $uuid;
        $devData['serial_number'] = $serial;
        $devData['initial_values'] = $initialValues;
        if ($address !== null && $address !== '') {
            $devData['address'] = $address;
        }
        if ($activeChannels !== null) {
            $devData['active_channels'] = array_values(array_map('intval', $activeChannels));
        }
        if ($channels !== null) {
            $devData['channels'] = $channels;
        }
        $devices[$key] = $devData;
        self::saveRegisteredDevices($devices);
    }

    public static function updateDeviceChannelBaseApiValue(string $serial, string $chNum, float $baseApiVal): void
    {
        $devices = self::loadRegisteredDevices();
        $key = isset($devices[(int) $serial]) ? (int) $serial : (isset($devices[$serial]) ? $serial : (int) $serial);
        if (!isset($devices[$key])) {
            return;
        }
        if (!isset($devices[$key]['channels'])) {
            $devices[$key]['channels'] = [];
        }
        if (!isset($devices[$key]['channels'][$chNum])) {
            $devices[$key]['channels'][$chNum] = [];
        }
        $devices[$key]['channels'][$chNum]['base_api_value'] = $baseApiVal;
        self::saveRegisteredDevices($devices);
    }

    public static function loadUserMeters(): array
    {
        return self::loadJsonWithLock(self::userStorageFile());
    }

    public static function saveUserMeters(array $data): void
    {
        self::atomicWriteJson(self::userStorageFile(), $data);
    }

    public static function getUserMeters(string $chatId): array
    {
        $all = self::loadUserMeters();
        $raw = $all[$chatId] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $s => $item) {
            $sStr = trim((string) $s);
            if ($sStr !== '' && $sStr !== '0') {
                $clean[$sStr] = $item;
            }
        }
        return $clean;
    }

    public static function addUserMeter(string $chatId, string $serial, string $name, string $deviceId = '', ?string $address = null): void
    {
        $serial = trim($serial);
        if ($serial === '' || $serial === '0') {
            return;
        }
        $all = self::loadUserMeters();
        if (!isset($all[$chatId])) {
            $all[$chatId] = [];
        }
        $meterData = [
            'name' => $name,
            'device_id' => $deviceId,
        ];
        if ($address !== null && $address !== '') {
            $meterData['address'] = $address;
        }
        $all[$chatId][$serial] = $meterData;
        self::saveUserMeters($all);
    }

    public static function removeUserMeter(string $chatId, string $serial): void
    {
        $all = self::loadUserMeters();
        if (isset($all[$chatId][$serial])) {
            unset($all[$chatId][$serial]);
            self::saveUserMeters($all);
        }
    }

    public static function loadMeterCache(): array
    {
        return self::loadJsonWithLock(self::meterCacheFile());
    }

    public static function saveMeterCache(array $data): void
    {
        self::atomicWriteJson(self::meterCacheFile(), $data);
    }
}
