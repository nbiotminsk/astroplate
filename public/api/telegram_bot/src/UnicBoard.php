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
     */
    public static function getDeviceValues(array $config, string $deviceUuid, int $limit = 10, ?string $periodFrom = null, int $timeout = 15): array
    {
        $headers = self::unicboardHeaders($config);
        $apiBase = $config['unicboard_api_base'];

        if ($periodFrom === null) {
            $periodFrom = date('Y-m-d\T00:00:00', strtotime('-30 days'));
        }

        $url = $apiBase . '/api/v1/devices/values?limit=' . $limit . '&period_from=' . urlencode($periodFrom);

        // 1. Сначала пробуем "devices_id" — официальное имя параметра из OpenAPI спецификации
        [$code, $resp] = Telegram::httpPostJson($url, ['devices_id' => [$deviceUuid]], $headers, $timeout);
        $payload = $resp['payload'] ?? [];

        // На холодном старте при cURL таймауте ($code === 0) делаем одну повторную попытку
        if ($code === 0 && empty($payload)) {
            [$code, $resp] = Telegram::httpPostJson($url, ['devices_id' => [$deviceUuid]], $headers, $timeout);
            $payload = $resp['payload'] ?? [];
        }

        // 2. Вариации параметров в боди, если основной вызов вернул пустой payload
        if (empty($payload)) {
            [$code, $resp] = Telegram::httpPostJson($url, ['device_ids' => [$deviceUuid]], $headers, $timeout);
            $payload = $resp['payload'] ?? [];
        }

        if (empty($payload)) {
            [$code, $resp] = Telegram::httpPostJson($url, ['devices' => [$deviceUuid]], $headers, $timeout);
            $payload = $resp['payload'] ?? [];
        }

        // 3. Fallback: GET /api/v1/devices/{id}/values
        if (empty($payload)) {
            $fallbackUrl = $apiBase . '/api/v1/devices/' . $deviceUuid . '/values?limit=' . $limit;
            [$fbCode, $fbResp] = Telegram::httpGet($fallbackUrl, $headers, $timeout);
            if ($fbCode === 200 && !empty($fbResp['payload'])) {
                $payload = $fbResp['payload'];
                $code = $fbCode;
            }
        }

        // 4. Fallback: GET /api/v1/devices/{id}/info
        if (empty($payload)) {
            $devUrl = $apiBase . '/api/v1/devices/' . $deviceUuid . '/info';
            [$dCode, $dResp] = Telegram::httpGet($devUrl, $headers, $timeout);
            if ($dCode === 200 && !empty($dResp['payload'])) {
                $payload = [$dResp['payload']];
                $code = $dCode;
            }
        }

        return [
            'http_code' => $code,
            'payload' => $payload,
            'errors' => $resp['errors'] ?? [],
            'ok' => !empty($payload),
        ];
    }

    /** Температура прибора */
    public static function getTemperature(array $config, string $deviceId, int $limit = 1): ?array
    {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/temperatures?limit=' . $limit;
        [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config), 5);
        if ($code !== 200 || !isset($resp['payload'][0])) {
            return null;
        }
        return $resp['payload'][0];
    }

    /** Уровень батареи прибора */
    public static function getBattery(array $config, string $deviceId, int $limit = 1): ?array
    {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/battery-level?limit=' . ($limit === 1 ? 10 : $limit);
        [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config), 5);
        if ($code !== 200 || !isset($resp['payload'][0])) {
            return null;
        }
        return $resp['payload'][0];
    }

    /**
     * Запрос всех доступных приборов через GET /api/v1/devices/info
     */
    public static function getAllDevices(array $config): array
    {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/info?limit=100';
        [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config));
        if ($code !== 200 || !isset($resp['payload']) || !is_array($resp['payload'])) {
            return [];
        }
        return $resp['payload'];
    }

    /**
     * Карта серийных номеров счетчиков по номерам каналов для прибора
     */
    public static function getDeviceChannelsSerials(array $config, string $deviceId): array
    {
        $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/info';
        [$code, $resp] = Telegram::httpGet($url, self::unicboardHeaders($config));
        if ($code !== 200 || !isset($resp['payload']['device_channel'])) {
            return [];
        }

        $serials = [];
        foreach ($resp['payload']['device_channel'] as $idx => $ch) {
            $chNum = $idx + 1;
            if (isset($ch['serial_number'])) {
                $serials[$chNum] = (string) $ch['serial_number'];
            }
        }
        return $serials;
    }
}
