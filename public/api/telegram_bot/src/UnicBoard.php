<?php

declare(strict_types=1);

namespace TelegramBot;

class UnicBoard
{
    public static bool $enableDiagnostics = false;

    public static function unicboardHeaders(array $config): array
    {
        return [
            'Authorization: Bearer ' . ($config['unicboard_token'] ?? ''),
            'Accept: application/json',
        ];
    }

    public static function shouldLogDiagnostic(array $config = []): bool
    {
        return (bool) ($config['enable_diagnostics'] ?? self::$enableDiagnostics);
    }

    /**
     * HTTP success is not API success: the response must explicitly contain ok=true.
     */
    private static function hasApiSuccess(mixed $resp): bool
    {
        return is_array($resp) && array_key_exists('ok', $resp) && $resp['ok'] === true;
    }

    /**
     * Запись структурированной диагностики запросов (без секретов и токенов).
     */
    public static function logDiagnostic(
        string $endpoint,
        string $deviceId,
        int $attempt,
        int $httpStatus,
        bool $ok,
        ?int $payloadCount,
        float $durationMs,
        array $errors = [],
        array $extra = [],
        array $config = []
    ): void {
        if (!self::shouldLogDiagnostic($config)) {
            return;
        }

        $requestVariant = $extra['request_variant'] ?? 'primary';
        $totalCount = array_key_exists('total_count', $extra) ? $extra['total_count'] : null;

        $entry = [
            'tag' => 'UNICBOARD_API',
            'time' => date('Y-m-d\TH:i:sP'),
            'endpoint' => $endpoint,
            'device_id' => $deviceId,
            'attempt' => $attempt,
            'request_variant' => $requestVariant,
            'http_status' => $httpStatus,
            'ok' => $ok,
            'payload_count' => $payloadCount,
            'duration_ms' => round($durationMs, 2),
        ];

        if ($totalCount !== null) {
            $entry['total_count'] = $totalCount;
        }

        if (!empty($errors)) {
            $entry['errors'] = $errors;
        }
        if (!empty($extra)) {
            $entry['extra'] = $extra;
        }

        error_log(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Полные показания по одному device_id через POST /api/v1/devices/values
     *
     * @return array{http_status: int, ok: bool, payload: array, count: ?int, total_count: ?int, errors: array}
     */
    public static function getDeviceValues(
        array $config,
        string $deviceUuid,
        int $limit = 50,
        ?string $periodFrom = null,
        int $timeout = 15,
        ?string $periodTo = null,
        bool $endOfDay = true,
        ?string $journalDataType = null,
        int $maxRetries = 3,
        int $retryDelayUs = 500000,
        ?callable $httpPostJson = null,
        ?callable $httpGet = null
    ): array {
        $headers = self::unicboardHeaders($config);
        $apiBase = rtrim((string) ($config['unicboard_api_base'] ?? ''), '/');
        $httpPostJson ??= [Telegram::class, 'httpPostJson'];
        $httpGet ??= [Telegram::class, 'httpGet'];

        if ($periodFrom === null) {
            $periodFrom = date('Y-m-d\T00:00:00', strtotime('-30 days'));
        }

        $queryParams = [
            'limit' => $limit,
            'period_from' => $periodFrom,
            'end_of_day' => $endOfDay ? 'true' : 'false',
        ];
        if ($periodTo !== null) {
            $queryParams['period_to'] = $periodTo;
        }
        if ($journalDataType !== null) {
            $queryParams['journal_data_type'] = $journalDataType;
        }

        $url = $apiBase . '/api/v1/devices/values?' . http_build_query($queryParams);
        $payload = [];
        $resp = null;
        $code = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $variant = $attempt === 1 ? 'post_devices_id' : 'retry_post_devices_id';

            $startTs = microtime(true);
            [$code, $resp] = $httpPostJson($url, ['devices_id' => [$deviceUuid]], $headers, $timeout);
            $durationMs = (microtime(true) - $startTs) * 1000;

            $isJsonArray = is_array($resp);
            $apiOk = self::hasApiSuccess($resp);
            $payload = $isJsonArray && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : [];
            $payloadCount = count($payload);
            $errors = $isJsonArray && isset($resp['errors']) && is_array($resp['errors']) ? $resp['errors'] : [];
            $totalCount = $isJsonArray ? ($resp['total_count'] ?? null) : null;

            $firstRec = $payload[0] ?? [];
            $extraDiag = array_filter([
                'journal_data_type' => $journalDataType ?? ($firstRec['journal_data_type'] ?? null),
                'value_type' => $firstRec['value_type'] ?? null,
                'request_variant' => $variant,
                'total_count' => $totalCount,
            ], static fn($v) => $v !== null);

            self::logDiagnostic(
                'POST /api/v1/devices/values',
                $deviceUuid,
                $attempt,
                $code,
                $apiOk,
                $payloadCount,
                $durationMs,
                $errors,
                $extraDiag,
                $config
            );

            // 1. Успех: HTTP 200, API ok=true и непустой payload -> выходим немедленно
            if ($code === 200 && $apiOk && !empty($payload)) {
                break;
            }

            // 2. Ошибки клиента 4xx (например 401 Unauthorized, 404 Not Found) — не повторяем
            if ($code >= 400 && $code < 500) {
                break;
            }

            // 3. Холодный старт (HTTP 200, ok=true, но payload=[]), сетевой сбой (code=0),
            // 5xx или API ok=false — повторяем с задержкой в пределах maxRetries
            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }

        $finalOk = $code === 200 && self::hasApiSuccess($resp);

        return [
            'http_status' => $code,
            'ok' => $finalOk,
            'payload' => $payload,
            'count' => is_array($resp) ? ($resp['count'] ?? $payloadCount) : null,
            'total_count' => is_array($resp) ? ($resp['total_count'] ?? null) : null,
            'errors' => is_array($resp) ? ($resp['errors'] ?? []) : [],
        ];
    }

    /**
     * Информация по конкретному прибору GET /api/v1/devices/{device_id}/info
     *
     * @return array{http_status: int, ok: bool, payload: ?array, count: ?int, total_count: ?int, errors: array}
     */
    public static function getDeviceInfo(
        array $config,
        string $deviceId,
        int $timeout = 10,
        int $maxRetries = 3,
        int $retryDelayUs = 500000,
        ?callable $httpGet = null
    ): array {
        $apiBase = rtrim((string) ($config['unicboard_api_base'] ?? ''), '/');
        $url = $apiBase . '/api/v1/devices/' . $deviceId . '/info';
        $headers = self::unicboardHeaders($config);
        $httpGet ??= [Telegram::class, 'httpGet'];
        $resp = null;
        $code = 0;
        $finalPayload = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $variant = match ($attempt) {
                1 => 'get_device_id_info',
                2 => 'retry_get_device_id_info',
                default => 'get_all_devices_fallback',
            };

            $startTs = microtime(true);

            if ($attempt >= 3) {
                // Альтернативный валидный запрос: список всех устройств GET /api/v1/devices/info
                $allUrl = $apiBase . '/api/v1/devices/info?limit=100';
                [$code, $resp] = $httpGet($allUrl, $headers, $timeout);
                $durationMs = (microtime(true) - $startTs) * 1000;

                $isJsonArray = is_array($resp);
                $apiOk = self::hasApiSuccess($resp);
                $deviceItem = null;
                if ($isJsonArray && isset($resp['payload']) && is_array($resp['payload'])) {
                    if (isset($resp['payload'][0]) || empty($resp['payload'])) {
                        foreach ($resp['payload'] as $item) {
                            if (is_array($item) && ($item['id'] ?? null) === $deviceId) {
                                $deviceItem = $item;
                                break;
                            }
                        }
                    } elseif (($resp['payload']['id'] ?? null) === $deviceId) {
                        $deviceItem = $resp['payload'];
                    }
                }

                $payload = $deviceItem;
                $errors = $isJsonArray && isset($resp['errors']) && is_array($resp['errors']) ? $resp['errors'] : [];
                $hasCompleteChannels = self::hasCompleteDeviceInfoPayload($payload, $deviceId);
                $channelCount = ($payload && isset($payload['device_channel']) && is_array($payload['device_channel']))
                    ? count($payload['device_channel'])
                    : 0;

                $channelsDiag = self::buildChannelsDiagnostic($payload);

                self::logDiagnostic(
                    'GET /api/v1/devices/info',
                    $deviceId,
                    $attempt,
                    $code,
                    $apiOk,
                    $channelCount,
                    $durationMs,
                    $errors,
                    [
                        'request_variant' => $variant,
                        'channels' => $channelsDiag,
                        'response_shape' => [
                            'is_json_array' => $isJsonArray,
                            'has_ok_field' => $isJsonArray && array_key_exists('ok', $resp),
                            'has_payload' => $payload !== null,
                            'has_expected_device_id' => $payload !== null && ($payload['id'] ?? null) === $deviceId,
                            'has_complete_device_channels' => $hasCompleteChannels,
                        ],
                    ],
                    $config
                );

                if ($code === 200 && $apiOk && $hasCompleteChannels) {
                    $finalPayload = $payload;
                    break;
                }
            } else {
                [$code, $resp] = $httpGet($url, $headers, $timeout);
                $durationMs = (microtime(true) - $startTs) * 1000;

                $isJsonArray = is_array($resp);
                $apiOk = self::hasApiSuccess($resp);
                $payload = $isJsonArray && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : null;
                $errors = $isJsonArray && isset($resp['errors']) && is_array($resp['errors']) ? $resp['errors'] : [];
                $hasCompleteChannels = self::hasCompleteDeviceInfoPayload($payload, $deviceId);

                $channelCount = ($payload && isset($payload['device_channel']) && is_array($payload['device_channel']))
                    ? count($payload['device_channel'])
                    : 0;

                $channelsDiag = self::buildChannelsDiagnostic($payload);

                self::logDiagnostic(
                    'GET /api/v1/devices/{id}/info',
                    $deviceId,
                    $attempt,
                    $code,
                    $apiOk,
                    $channelCount,
                    $durationMs,
                    $errors,
                    [
                        'request_variant' => $variant,
                        'channels' => $channelsDiag,
                        'response_shape' => [
                            'is_json_array' => $isJsonArray,
                            'has_ok_field' => $isJsonArray && array_key_exists('ok', $resp),
                            'has_payload' => $payload !== null,
                            'has_expected_device_id' => $payload !== null && ($payload['id'] ?? null) === $deviceId,
                            'has_complete_device_channels' => $hasCompleteChannels,
                        ],
                    ],
                    $config
                );

                if ($code === 200 && $apiOk && $hasCompleteChannels) {
                    $finalPayload = $payload;
                    break;
                }
            }

            if ($code >= 400 && $code < 500) {
                break;
            }

            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }

        if ($finalPayload === null && is_array($resp) && isset($resp['payload']) && is_array($resp['payload']) && !isset($resp['payload'][0])) {
            $finalPayload = $resp['payload'];
        }

        $finalOk = $code === 200
            && self::hasApiSuccess($resp)
            && ($finalPayload !== null);

        return [
            'http_status' => $code,
            'ok' => $finalOk,
            'payload' => $finalPayload,
            'count' => is_array($resp) ? ($resp['count'] ?? ($finalPayload !== null ? 1 : null)) : null,
            'total_count' => is_array($resp) ? ($resp['total_count'] ?? null) : null,
            'errors' => is_array($resp) ? ($resp['errors'] ?? []) : [],
        ];
    }

    /**
     * Формирует массив диагностики каналов для логирования /info.
     */
    private static function buildChannelsDiagnostic(?array $payload): array
    {
        $channelsDiag = [];
        if ($payload && isset($payload['device_channel']) && is_array($payload['device_channel'])) {
            foreach ($payload['device_channel'] as $idx => $ch) {
                if (!is_array($ch)) {
                    continue;
                }
                $chNum = isset($ch['serial_number']) && is_numeric($ch['serial_number']) ? (int) $ch['serial_number'] : ($idx + 1);
                $m = (!empty($ch['device_meter']) && is_array($ch['device_meter'])) ? ($ch['device_meter'][0] ?? []) : [];
                $channelsDiag[] = [
                    'channel_number' => $chNum,
                    'last_value' => isset($m['last_value']) && is_numeric($m['last_value']) ? (float) $m['last_value'] : null,
                    'last_value_date' => $m['last_value_date'] ?? null,
                    'is_alive' => $ch['is_alive'] ?? $m['is_alive'] ?? null,
                    'inactivity_limit' => $ch['inactivity_limit'] ?? null,
                    'last_date_event_no_data' => $ch['last_date_event_no_data'] ?? null,
                ];
            }
        }
        return $channelsDiag;
    }

    /**
     * Проверяет соответствие структуры /info спецификации api.json и соответствие device_id.
     * В соответствии со схемой ApiDeviceResponse, объект прибора должен содержать id,
     * соответствующий запрошенному, и массив device_channel.
     * В соответствии со схемой ApiDeviceChannelPayloadResponse, каждый канал должен содержать
     * обязательные поля serial_number и массив device_meter.
     * Пустой список каналов или отсутствующая структура счетчиков считается временно неполным ответом.
     */
    public static function hasCompleteDeviceInfoPayload(?array $payload, string $deviceId): bool
    {
        if ($payload === null
            || !isset($payload['id'])
            || !is_string($payload['id'])
            || $payload['id'] !== $deviceId
            || !isset($payload['device_channel'])
            || !is_array($payload['device_channel'])
            || $payload['device_channel'] === []) {
            return false;
        }

        foreach ($payload['device_channel'] as $channel) {
            if (!is_array($channel)
                || !isset($channel['serial_number'])
                || !is_numeric($channel['serial_number'])
                || !array_key_exists('device_meter', $channel)
                || !is_array($channel['device_meter'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Температура прибора GET /api/v1/devices/{device_id}/temperatures
     *
     * @return array{http_status: int, ok: bool, payload: array, errors: array}
     */
    public static function getTemperature(
        array $config,
        string $deviceId,
        int $limit = 1,
        int $timeout = 10,
        int $maxRetries = 3,
        int $retryDelayUs = 500000,
        ?callable $httpGet = null
    ): array {
        $apiBase = rtrim((string) ($config['unicboard_api_base'] ?? ''), '/');
        $url = $apiBase . '/api/v1/devices/' . $deviceId . '/temperatures?limit=' . $limit;
        $headers = self::unicboardHeaders($config);
        $httpGet ??= [Telegram::class, 'httpGet'];
        $resp = null;
        $code = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $variant = $attempt === 1 ? 'get_temperature' : 'retry_get_temperature';
            $startTs = microtime(true);
            [$code, $resp] = $httpGet($url, $headers, $timeout);
            $durationMs = (microtime(true) - $startTs) * 1000;

            $isJsonArray = is_array($resp);
            $apiOk = self::hasApiSuccess($resp);
            $payload = $isJsonArray && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : [];
            $errors = $isJsonArray && isset($resp['errors']) && is_array($resp['errors']) ? $resp['errors'] : [];

            self::logDiagnostic(
                'GET /api/v1/devices/{id}/temperatures',
                $deviceId,
                $attempt,
                $code,
                $apiOk,
                count($payload),
                $durationMs,
                $errors,
                ['request_variant' => $variant],
                $config
            );

            if ($code === 200 && $apiOk && !empty($payload)) {
                break;
            }

            if ($code >= 400 && $code < 500) {
                break;
            }

            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }

        $finalOk = $code === 200 && self::hasApiSuccess($resp);

        return [
            'http_status' => $code,
            'ok' => $finalOk,
            'payload' => is_array($resp) && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : [],
            'errors' => is_array($resp) ? ($resp['errors'] ?? []) : [],
        ];
    }

    /**
     * Получить последнюю запись температуры прибора (или null)
     */
    public static function getLatestTemperature(array $config, string $deviceId, int $timeout = 10, ?callable $httpGet = null): ?array
    {
        $res = self::getTemperature($config, $deviceId, 1, $timeout, httpGet: $httpGet);
        return $res['payload'][0] ?? null;
    }

    /**
     * Уровень батареи прибора GET /api/v1/devices/{device_id}/battery-level
     *
     * @return array{http_status: int, ok: bool, payload: array, errors: array}
     */
    public static function getBattery(
        array $config,
        string $deviceId,
        int $limit = 1,
        int $timeout = 10,
        int $maxRetries = 3,
        int $retryDelayUs = 500000,
        ?callable $httpGet = null
    ): array {
        $apiBase = rtrim((string) ($config['unicboard_api_base'] ?? ''), '/');
        $url = $apiBase . '/api/v1/devices/' . $deviceId . '/battery-level?limit=' . ($limit === 1 ? 10 : $limit);
        $headers = self::unicboardHeaders($config);
        $httpGet ??= [Telegram::class, 'httpGet'];
        $resp = null;
        $code = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $variant = $attempt === 1 ? 'get_battery' : 'retry_get_battery';
            $startTs = microtime(true);
            [$code, $resp] = $httpGet($url, $headers, $timeout);
            $durationMs = (microtime(true) - $startTs) * 1000;

            $isJsonArray = is_array($resp);
            $apiOk = self::hasApiSuccess($resp);
            $payload = $isJsonArray && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : [];
            $errors = $isJsonArray && isset($resp['errors']) && is_array($resp['errors']) ? $resp['errors'] : [];

            self::logDiagnostic(
                'GET /api/v1/devices/{id}/battery-level',
                $deviceId,
                $attempt,
                $code,
                $apiOk,
                count($payload),
                $durationMs,
                $errors,
                ['request_variant' => $variant],
                $config
            );

            if ($code === 200 && $apiOk && !empty($payload)) {
                break;
            }

            if ($code >= 400 && $code < 500) {
                break;
            }

            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }

        $finalOk = $code === 200 && self::hasApiSuccess($resp);

        return [
            'http_status' => $code,
            'ok' => $finalOk,
            'payload' => is_array($resp) && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : [],
            'errors' => is_array($resp) ? ($resp['errors'] ?? []) : [],
        ];
    }

    /**
     * Получить последнюю запись батареи прибора (или null)
     */
    public static function getLatestBattery(array $config, string $deviceId, int $timeout = 10, ?callable $httpGet = null): ?array
    {
        $res = self::getBattery($config, $deviceId, 1, $timeout, httpGet: $httpGet);
        return $res['payload'][0] ?? null;
    }

    /**
     * Запрос всех доступных приборов через GET /api/v1/devices/info
     *
     * @return array{http_status: int, ok: bool, payload: array, count: ?int, total_count: ?int, errors: array}
     */
    public static function getAllDevices(
        array $config,
        int $limit = 100,
        int $timeout = 15,
        int $maxRetries = 3,
        int $retryDelayUs = 500000,
        ?callable $httpGet = null
    ): array {
        $apiBase = rtrim((string) ($config['unicboard_api_base'] ?? ''), '/');
        $url = $apiBase . '/api/v1/devices/info?limit=' . $limit;
        $headers = self::unicboardHeaders($config);
        $httpGet ??= [Telegram::class, 'httpGet'];
        $resp = null;
        $code = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $variant = $attempt === 1 ? 'get_all_devices' : 'retry_get_all_devices';
            $startTs = microtime(true);
            [$code, $resp] = $httpGet($url, $headers, $timeout);
            $durationMs = (microtime(true) - $startTs) * 1000;

            $isJsonArray = is_array($resp);
            $apiOk = self::hasApiSuccess($resp);
            $payload = $isJsonArray && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : [];
            $errors = $isJsonArray && isset($resp['errors']) && is_array($resp['errors']) ? $resp['errors'] : [];

            self::logDiagnostic(
                'GET /api/v1/devices/info',
                'all',
                $attempt,
                $code,
                $apiOk,
                count($payload),
                $durationMs,
                $errors,
                ['request_variant' => $variant],
                $config
            );

            if ($code === 200 && $apiOk && !empty($payload)) {
                break;
            }

            if ($code >= 400 && $code < 500) {
                break;
            }

            if ($attempt < $maxRetries) {
                usleep($retryDelayUs);
            }
        }

        $finalOk = $code === 200 && self::hasApiSuccess($resp);

        return [
            'http_status' => $code,
            'ok' => $finalOk,
            'payload' => is_array($resp) && isset($resp['payload']) && is_array($resp['payload']) ? $resp['payload'] : [],
            'count' => is_array($resp) ? ($resp['count'] ?? null) : null,
            'total_count' => is_array($resp) ? ($resp['total_count'] ?? null) : null,
            'errors' => is_array($resp) ? ($resp['errors'] ?? []) : [],
        ];
    }
}
