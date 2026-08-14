<?php

declare(strict_types=1);

namespace TelegramBot;

class UnicBoard
{
    public static function unicboardHeaders(array $config): array
    {
        return ['Authorization: Bearer ' . $config['unicboard_token']];
    }

    /**
     * Полные показания по одному device_id через POST /api/v1/devices/values
     * При холодном старте или пустом payload при 200 делает повторные попытки с задержкой 0.5 сек.
     */
    public static function getDeviceValues(
        array $config,
        string $deviceUuid,
        int $limit = 10,
        ?string $periodFrom = null,
        int $timeout = 15,
        int $maxRetries = 3,
        int $retryDelayUs = 500000 // 0.5 секунды
    ): array {
        $headers = self::unicboardHeaders($config);
        $apiBase = $config['unicboard_api_base'];

        if ($periodFrom === null) {
            $periodFrom = date('Y-m-d\T00:00:00', strtotime('-30 days'));
        }

        $url = $apiBase . '/api/v1/devices/values?limit=' . $limit . '&period_from=' . urlencode($periodFrom);
        $payload = [];
        $resp = [];
        $code = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            [$code, $resp] = Telegram::httpPostJson($url, ['devices_id' => [$deviceUuid]], $headers, $timeout);
            $payload = $resp['payload'] ?? [];

            // Если данные успешно получены, выходим
            if (!empty($payload)) {
                break;
            }

            // На холодном старте (пустой payload при 200 или сбой подключения code 0) ждем 0.5с и повторяем
            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }

        return [
            'http_code' => $code,
            'payload' => $payload,
            'errors' => $resp['errors'] ?? [],
            'ok' => !empty($payload),
        ];
    }

    /**
     * Информация по конкретному прибору GET /api/v1/devices/{device_id}/info
     */
    public static function getDeviceInfo(
        array $config,
        string $deviceId,
        int $timeout = 10,
        int $maxRetries = 3,
        int $retryDelayUs = 500000
    ): ?array {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/info';
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config), $timeout);
            if ($code === 200 && isset($resp['payload']) && is_array($resp['payload'])) {
                return $resp['payload'];
            }
            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }
        return null;
    }

    /** Температура прибора */
    public static function getTemperature(
        array $config,
        string $deviceId,
        int $limit = 1,
        int $timeout = 10,
        int $maxRetries = 3,
        int $retryDelayUs = 500000
    ): ?array {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/temperatures?limit=' . $limit;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config), $timeout);
            if ($code === 200 && isset($resp['payload'][0])) {
                return $resp['payload'][0];
            }
            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }
        return null;
    }

    /** Уровень батареи прибора */
    public static function getBattery(
        array $config,
        string $deviceId,
        int $limit = 1,
        int $timeout = 10,
        int $maxRetries = 3,
        int $retryDelayUs = 500000
    ): ?array {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/battery-level?limit=' . ($limit === 1 ? 10 : $limit);
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config), $timeout);
            if ($code === 200 && isset($resp['payload'][0])) {
                return $resp['payload'][0];
            }
            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }
        return null;
    }

    /**
     * Запрос всех доступных приборов через GET /api/v1/devices/info
     */
    public static function getAllDevices(
        array $config,
        int $timeout = 15,
        int $maxRetries = 3,
        int $retryDelayUs = 500000
    ): array {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/info?limit=100';
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config), $timeout);
            if ($code === 200 && isset($resp['payload']) && is_array($resp['payload'])) {
                return $resp['payload'];
            }
            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }
        return [];
    }

    /**
     * Карта серийных номеров счетчиков по номерам каналов для прибора
     */
    public static function getDeviceChannelsSerials(array $config, string $deviceId): array
    {
        $payload = self::getDeviceInfo($config, $deviceId);
        if (!$payload || !isset($payload['device_channel'])) {
            return [];
        }

        $serials = [];
        foreach ($payload['device_channel'] as $idx => $ch) {
            $chNum = $idx + 1;
            if (isset($ch['serial_number'])) {
                $serials[$chNum] = (string) $ch['serial_number'];
            }
        }
        return $serials;
    }
}
