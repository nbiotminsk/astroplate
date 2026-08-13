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

    public static function loadRegisteredDevices(): array
    {
        return self::loadJsonWithLock(self::customDevicesFile());
    }

    public static function saveRegisteredDevices(array $devices): void
    {
        self::atomicWriteJson(self::customDevicesFile(), $devices);
    }

    public static function registerCustomDevice(string $serial, string $uuid, string $name, array $initialValues = []): void
    {
        $devices = self::loadRegisteredDevices();
        $devices[(int) $serial] = [
            'name' => $name,
            'device_id' => $uuid,
        ];
        if (!empty($initialValues)) {
            $devices[(int) $serial]['initial_values'] = $initialValues;
        }
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
        return $all[$chatId] ?? [];
    }

    public static function addUserMeter(string $chatId, string $serial, string $name): void
    {
        $all = self::loadUserMeters();
        if (!isset($all[$chatId])) {
            $all[$chatId] = [];
        }
        $all[$chatId][$serial] = $name;
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
