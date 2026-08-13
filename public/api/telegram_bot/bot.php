<?php
/**
 * Telegram-бот для получения данных приборов UnicBoard.
 *
 * Работает через long-polling (getUpdates), поэтому запускается из CLI:
 *     php bot.php
 *
 * Создайте конфиг (config.php) и укажите токены перед запуском.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

const TO_CMD = <<<TXT
Команды бота:
/start — запустить бота и открыть меню
/add СЕРИЙНЫЙ_№ UUID НАЗВАНИЕ — добавить новый прибор в систему
Пример: <code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f Fluo</code>
/init СЕРИЙНЫЙ_№ КАНАЛ ЗНАЧЕНИЕ — задать/изменить начальное показание счетчика
Пример: <code>/init 8527038 1 0.12</code>
/my — список моих сохраненных счетчиков
TXT;

/* ==================== Хранилище приборов и пользователей ==================== */

function custom_devices_file(): string
{
    return __DIR__ . '/registered_devices.json';
}

function atomic_write_json(string $filePath, array $data): void
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

function load_json_with_lock(string $filePath): array
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

function load_registered_devices(): array
{
    return load_json_with_lock(custom_devices_file());
}

function register_custom_device(string $serial, string $uuid, string $name, array $initialValues = []): void
{
    $devices = load_registered_devices();
    $devices[(int) $serial] = [
        'name' => $name,
        'device_id' => $uuid,
    ];
    if (!empty($initialValues)) {
        $devices[(int) $serial]['initial_values'] = $initialValues;
    }
    atomic_write_json(custom_devices_file(), $devices);
}

/**
 * Автоматически получает первичное/начальное показание с API прибора и сохраняет в registered_devices.json
 */
function fetch_and_save_initial_values(array $config, string $serial, string $deviceId, array $explicitInitial = []): array
{
    $customDevices = load_registered_devices();
    $existing = $customDevices[(int) $serial]['initial_values'] ?? [];

    if (!empty($explicitInitial)) {
        $initialValues = $explicitInitial;
    } elseif (!empty($existing)) {
        return $existing;
    } else {
        // Опрашиваем API на предмет начальных показаний / стартовых значений
        $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/info';
        [$code, $resp] = http_get($url, unicboard_headers($config));

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
        atomic_write_json(custom_devices_file(), $customDevices);
    }

    return $initialValues;
}

function user_storage_file(): string
{
    return __DIR__ . '/user_meters.json';
}

function load_user_meters(): array
{
    return load_json_with_lock(user_storage_file());
}

function save_user_meters(array $data): void
{
    atomic_write_json(user_storage_file(), $data);
}

function get_user_meters(string $chatId): array
{
    $all = load_user_meters();
    return $all[$chatId] ?? [];
}

function meter_cache_file(): string
{
    return __DIR__ . '/meter_cache.json';
}

function load_meter_cache(): array
{
    return load_json_with_lock(meter_cache_file());
}

function save_meter_cache(array $data): void
{
    atomic_write_json(meter_cache_file(), $data);
}

function extract_channel_records(array $payload, int $chNum): array
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
                    $val = extract_record_value($combined);
                    $date = extract_record_date($combined);
                    if ($val !== null && $date !== null) {
                        $records[] = ['val' => $val, 'date' => $date];
                    }
                }
            }
        } else {
            $chData = $v['device_meter'] ?? $v;
            $num = $chData['channel_number'] ?? $v['channel_number'] ?? 1;
            if ((int) $num === (int) $chNum) {
                $val = extract_record_value($chData);
                $date = extract_record_date($chData);
                if ($val !== null && $date !== null) {
                    $records[] = ['val' => $val, 'date' => $date];
                }
            }
        }
    }
    return $records;
}

/**
 * Получает или обновляет кэшированные данные о последнем расходе счетчика
 */
function get_meter_consumption_info(array $config, string $deviceId, int $chNum, ?float $currentVal, ?string $currentDate, array $monthRecords = []): array
{
    $cache = load_meter_cache();
    $devCache = $cache[$deviceId]['channels'][$chNum] ?? null;

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
            save_meter_cache($cache);
            return $devCache;
        }

        // 2. Если дата расхода пуста, пробоем заполнить из записей текущего месяца
        if (empty($devCache['last_change_date']) && !empty($monthRecords)) {
            $parsed = [];
            foreach ($monthRecords as $r) {
                $v = extract_record_value($r);
                $d = extract_record_date($r);
                if ($v !== null && $d !== null) {
                    $parsed[] = ['val' => $v, 'date' => $d];
                }
            }
            usort($parsed, function($a, $b) {
                return strtotime($a['date']) - strtotime($b['date']);
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

            if (!empty($devCache['last_change_date'])) {
                $cache[$deviceId]['channels'][$chNum] = $devCache;
                save_meter_cache($cache);
            }
        }

        return $devCache;
    }

    // Кэш отсутствует — строим историю
    $records = [];
    if (!empty($monthRecords)) {
        foreach ($monthRecords as $r) {
            $v = extract_record_value($r);
            $d = extract_record_date($r);
            if ($v !== null && $d !== null) {
                $records[] = ['val' => $v, 'date' => $d];
            }
        }
    }

    $lastVal = null;
    $changeDate = null;
    $changeDiff = null;
    $firstDate = null;

    usort($records, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
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
        $history = get_device_values($config, $deviceId, 500, date('Y-m-d', strtotime('-1 year')));
        if (!empty($history['payload'])) {
            $hRecords = extract_channel_records($history['payload'], $chNum);
            usort($hRecords, function($a, $b) {
                return strtotime($a['date']) - strtotime($b['date']);
            });

            $hLastVal = null;
            foreach ($hRecords as $r) {
                if ($firstDate === null) {
                    $firstDate = $r['date'];
                }
                if ($hLastVal === null) {
                    $hLastVal = $r['val'];
                } elseif (round($r['val'], 4) != round($hLastVal, 4)) {
                    $changeDate = $r['date'];
                    $changeDiff = round(abs($r['val'] - $hLastVal), 4);
                    $hLastVal = $r['val'];
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

    if (!isset($cache[$deviceId])) {
        $cache[$deviceId] = ['channels' => []];
    }
    $cache[$deviceId]['channels'][$chNum] = $info;
    save_meter_cache($cache);

    return $info;
}

function add_user_meter(string $chatId, string $serial, string $name): void
{
    $all = load_user_meters();
    if (!isset($all[$chatId])) {
        $all[$chatId] = [];
    }
    $all[$chatId][$serial] = $name;
    save_user_meters($all);
}

function remove_user_meter(string $chatId, string $serial): void
{
    $all = load_user_meters();
    if (isset($all[$chatId][$serial])) {
        unset($all[$chatId][$serial]);
        save_user_meters($all);
    }
}

function build_main_reply_keyboard(string $chatId): array
{
    $meters = get_user_meters($chatId);
    $keyboard = [];

    $buttons = [];
    foreach ($meters as $serial => $name) {
        $buttons[] = ['text' => "💧 {$name} ({$serial})"];
        if (count($buttons) === 2) {
            $keyboard[] = $buttons;
            $buttons = [];
        }
    }
    if (!empty($buttons)) {
        $keyboard[] = $buttons;
    }

    $keyboard[] = [
        ['text' => '➕ Добавить счетчик'],
        ['text' => '📋 Мои счетчики']
    ];

    return [
        'keyboard' => $keyboard,
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
    ];
}

/* ==================== HTTP helpers ==================== */

function http_get(string $url, array $headers = [], int $timeout = 15, int $connectTimeout = 5): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        error_log('cURL Error (GET ' . $url . '): ' . curl_error($ch));
        @curl_close($ch);
        return [0, null];
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    @curl_close($ch);

    return [$code, json_decode((string) $body, true)];
}

function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 15, int $connectTimeout = 5): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array_merge(
            ['Content-Type: application/json'],
            $headers
        ),
    ]);
    $data = curl_exec($ch);
    if ($data === false) {
        error_log('cURL Error (POST ' . $url . '): ' . curl_error($ch));
        @curl_close($ch);
        return [0, null];
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    @curl_close($ch);

    return [$code, json_decode((string) $data, true)];
}

/* ==================== UnicBoard API ==================== */

function unicboard_headers(array $config): array
{
    return ['Authorization: Bearer ' . $config['unicboard_token']];
}

/**
 * Полные показания по одному device_id через POST /api/v1/devices/values
 */
function get_device_values(array $config, string $deviceUuid, int $limit = 10, ?string $periodFrom = null, int $timeout = 15): array
{
    $headers = unicboard_headers($config);
    $apiBase = $config['unicboard_api_base'];

    if ($periodFrom === null) {
        $periodFrom = date('Y-m-d\T00:00:00', strtotime('-30 days'));
    }

    $url = $apiBase . '/api/v1/devices/values?limit=' . $limit . '&period_from=' . urlencode($periodFrom);

    // 1. Сначала пробуем "devices_id" — официальное имя параметра из OpenAPI спецификации
    [$code, $resp] = http_post_json($url, ['devices_id' => [$deviceUuid]], $headers, $timeout);
    $payload = $resp['payload'] ?? [];

    // На холодном старте при cURL таймауте ($code === 0) делаем одну повторную попытку
    if ($code === 0 && empty($payload)) {
        [$code, $resp] = http_post_json($url, ['devices_id' => [$deviceUuid]], $headers, $timeout);
        $payload = $resp['payload'] ?? [];
    }

    // 2. Вариации параметров в боди, если основной вызов вернул пустой payload
    if (empty($payload)) {
        [$code, $resp] = http_post_json($url, ['device_ids' => [$deviceUuid]], $headers, $timeout);
        $payload = $resp['payload'] ?? [];
    }

    if (empty($payload)) {
        [$code, $resp] = http_post_json($url, ['devices' => [$deviceUuid]], $headers, $timeout);
        $payload = $resp['payload'] ?? [];
    }

    // 3. Fallback: GET /api/v1/devices/{id}/values
    if (empty($payload)) {
        $fallbackUrl = $apiBase . '/api/v1/devices/' . $deviceUuid . '/values?limit=' . $limit;
        [$fbCode, $fbResp] = http_get($fallbackUrl, $headers, $timeout);
        if ($fbCode === 200 && !empty($fbResp['payload'])) {
            $payload = $fbResp['payload'];
            $code = $fbCode;
        }
    }

    // 4. Fallback: GET /api/v1/devices/{id}/info
    if (empty($payload)) {
        $devUrl = $apiBase . '/api/v1/devices/' . $deviceUuid . '/info';
        [$dCode, $dResp] = http_get($devUrl, $headers, $timeout);
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
function get_temperature(array $config, string $deviceId, int $limit = 1): ?array
{
    $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/temperatures?limit=' . $limit;
    [$code, $resp] = http_get($url, unicboard_headers($config), 5);
    if ($code !== 200 || !isset($resp['payload'][0])) {
        return null;
    }
    return $resp['payload'][0];
}

/** Уровень батареи прибора */
function get_battery(array $config, string $deviceId, int $limit = 1): ?array
{
    $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/battery-level?limit=' . ($limit === 1 ? 10 : $limit);
    [$code, $resp] = http_get($url, unicboard_headers($config), 5);
    if ($code !== 200 || !isset($resp['payload'][0])) {
        return null;
    }
    return $resp['payload'][0];
}

/* ==================== Telegram API ==================== */

function tg_api(string $method, array $params, string $token): array
{
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    [$code, $resp] = http_post_json($url, $params);
    return $resp ?? ['ok' => false];
}

function send_message(string $chatId, string $text, string $token, ?array $replyMarkup = null): void
{
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }
    tg_api('sendMessage', $params, $token);
}

function answer_callback_query(string $callbackQueryId, string $token, string $text = ''): void
{
    tg_api('answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
    ], $token);
}

/**
 * Запрос всех доступных приборов через GET /api/v1/devices/info
 */
function get_all_devices(array $config): array
{
    $url = $config['unicboard_api_base'] . '/api/v1/devices/info?limit=100';
    [$code, $resp] = http_get($url, unicboard_headers($config));
    if ($code !== 200 || !isset($resp['payload']) || !is_array($resp['payload'])) {
        return [];
    }
    return $resp['payload'];
}

function device_lookup(array $config, string $input): ?array
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    // 1. Проверяем локальный конфиг config.php
    if (isset($config['devices'][(int) $input])) {
        $dev = $config['devices'][(int) $input];
        $dev['serial_number'] = (string) $input;
        return $dev;
    }

    foreach ($config['devices'] as $id => $info) {
        if (mb_strtolower($info['name'], 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
            $info['serial_number'] = (string) $id;
            return $info;
        }
    }

    // 2. Проверяем пользовательское динамическое хранилище registered_devices.json
    $customDevices = load_registered_devices();
    if (isset($customDevices[(int) $input])) {
        $dev = $customDevices[(int) $input];
        $dev['serial_number'] = (string) $input;
        return $dev;
    }

    foreach ($customDevices as $id => $info) {
        if (mb_strtolower($info['name'], 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
            $info['serial_number'] = (string) $id;
            return $info;
        }
    }

    // 3. Если не найден — пробуем динамически запросить список приборов через API
    $apiDevices = get_all_devices($config);
    foreach ($apiDevices as $item) {
        $serial = (string) ($item['manufacturer_serial_number'] ?? '');
        $devId = $item['id'] ?? '';
        $name = $item['device_modification']['name'] ?? $item['device_manufacturer']['name'] ?? "Устройство {$serial}";

        if ($serial === $input || mb_strtolower($name, 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
            $initialValues = fetch_and_save_initial_values($config, $serial, $devId);
            return [
                'name' => $name,
                'device_id' => $devId,
                'serial_number' => $serial,
                'initial_values' => $initialValues,
            ];
        }
    }

    return null;
}

/**
 * Карта серийных номеров счетчиков по номерам каналов для прибора
 */
function get_device_channels_serials(array $config, string $deviceId): array
{
    $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/info';
    [$code, $resp] = http_get($url, unicboard_headers($config));
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

function extract_record_value(array $rec): ?float
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
                    $val = extract_record_value($c);
                    if ($val !== null) {
                        return $val;
                    }
                }
            }
        }
    }
    return null;
}

function extract_record_date(array $rec): ?string
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
                    $val = extract_record_date($c);
                    if ($val !== null) {
                        return $val;
                    }
                }
            }
        }
    }
    return null;
}

function build_report(array $config, array $device): string
{
    $name = $device['name'];
    $deviceId = $device['device_id'];

    $lines = [];
    $lines[] = "\xF0\x9F\x93\xB1 <b>{$name}</b>";

    // Серийные номера и свежие показания счетчиков из /info
    $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/info';
    [$code, $infoResp] = http_get($url, unicboard_headers($config));

    $channelSerials = [];
    $liveChannels = [];
    if ($code === 200 && !empty($infoResp['payload']['device_channel'])) {
        foreach ($infoResp['payload']['device_channel'] as $idx => $ch) {
            $chNum = $ch['serial_number'] ?? ($idx + 1);
            if (isset($ch['serial_number'])) {
                $channelSerials[$chNum] = (string) $ch['serial_number'];
            }
            $liveChannels[$chNum] = $ch;
        }
    }

    // Показания по каналам (запрашиваем историю с запасом limit=50)
    $values = get_device_values($config, $deviceId, 50);
    $channelsHistory = [];

    // Добавляем текущие (живые) каналы из /info
    foreach ($liveChannels as $chNum => $chData) {
        $channelsHistory[$chNum][] = $chData;
    }

    if (!empty($values['payload'])) {
        foreach ($values['payload'] as $v) {
            $channelsList = [];
            if (isset($v['device_channel']) && is_array($v['device_channel'])) {
                $channelsList = $v['device_channel'];
            } elseif (isset($v['channels']) && is_array($v['channels'])) {
                $channelsList = $v['channels'];
            }

            if (!empty($channelsList)) {
                foreach ($channelsList as $idx => $chData) {
                    $chNum = $chData['channel_number'] ?? ($idx + 1);
                    $channelsHistory[$chNum][] = is_array($chData) ? array_merge($v, $chData) : $v;
                }
            } else {
                $ch = $v['channel_number'] ?? 1;
                $channelsHistory[$ch][] = $v;
            }
        }
        foreach ($channelsHistory as $chNum => &$hList) {
            usort($hList, function ($a, $b) {
                $tA = strtotime(extract_record_date($a) ?? '');
                $tB = strtotime(extract_record_date($b) ?? '');
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

            $initialValues = $device['initial_values'] ?? [];
            $offset = (float) ($initialValues[$chNum] ?? $initialValues[(string)$chNum] ?? 0);

            $rawVal = $latest ? extract_record_value($latest) : null;
            $lastVal = $rawVal !== null ? round($rawVal + $offset, 4) : null;
            $lastValDate = $latest ? extract_record_date($latest) : null;
            $dateStr = $lastValDate ? date('d.m.Y H:i', strtotime($lastValDate)) : '—';
            $valStr = $lastVal !== null ? (string) $lastVal : '—';

            // Обновляем кэш расхода при получении текущих показаний
            if ($lastVal !== null && $lastValDate !== null) {
                get_meter_consumption_info($config, $deviceId, (int)$chNum, $lastVal, $lastValDate, $history);
            }

            $meterSerial = $channelSerials[$chNum] ?? null;
            $meterLabel = $meterSerial ? "Счетчик № {$meterSerial}" : "Счетчик {$chNum}";

            $diffStr = '';
            if ($latest !== null && $prev !== null) {
                $prevVal = extract_record_value($prev);
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
    $temp = get_temperature($config, $deviceId, 1);
    if ($temp !== null) {
        $t = extract_record_value($temp) ?? $temp['value'] ?? null;
        $lines[] = "\xF0\x9F\x92\xA8 Температура: <b>" . ($t !== null ? $t : '—') . " °C</b>";
    } else {
        $lines[] = "\xF0\x9F\x92\xA8 Температура: нет данных";
    }

    // Батарея
    $bat = get_battery($config, $deviceId, 1);
    if ($bat !== null) {
        $b = extract_record_value($bat) ?? $bat['value'] ?? null;
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
function build_month_report(array $config, array $device): string
{
    $name = $device['name'];
    $deviceId = $device['device_id'];
    $initialValues = $device['initial_values'] ?? [];

    $firstDay = date('01.m.Y 00:00');
    $lastDay = date('d.m.Y H:i');

    $lines = [];
    $lines[] = "\xF0\x9F\x93\x85 <b>Архив за текущий месяц ({$name})</b>";
    $lines[] = "Период: <b>{$firstDay}</b> — <b>{$lastDay}</b>\n";

    $channelSerials = get_device_channels_serials($config, $deviceId);

    $startMonthTs = strtotime(date('Y-m-01 00:00:00'));
    $endMonthTs = time();

    // Запрашиваем только текущий месяц + свежие онлайн показания
    $values = get_device_values($config, $deviceId, 100, date('Y-m-01'));
    $latestLive = get_device_values($config, $deviceId, 1);

    $allPayloads = array_merge($values['payload'] ?? [], $latestLive['payload'] ?? []);
    $channelsMonthData = [];

    if (!empty($allPayloads)) {
        foreach ($allPayloads as $v) {
            $channelsList = [];
            if (isset($v['device_channel']) && is_array($v['device_channel'])) {
                $channelsList = $v['device_channel'];
            } elseif (isset($v['channels']) && is_array($v['channels'])) {
                $channelsList = $v['channels'];
            }

            if (!empty($channelsList)) {
                foreach ($channelsList as $idx => $chData) {
                    $ch = $chData['channel_number'] ?? ($idx + 1);
                    $combined = is_array($chData) ? array_merge($v, $chData) : $v;
                    $valDate = extract_record_date($combined);
                    if ($valDate) {
                        $ts = strtotime($valDate);
                        if ($ts >= $startMonthTs && $ts <= $endMonthTs) {
                            $channelsMonthData[$ch][] = $combined;
                        }
                    }
                }
            } else {
                $chData = $v['device_meter'] ?? $v;
                $ch = $chData['channel_number'] ?? $v['channel_number'] ?? 1;
                $valDate = extract_record_date($chData);
                if ($valDate) {
                    $ts = strtotime($valDate);
                    if ($ts >= $startMonthTs && $ts <= $endMonthTs) {
                        $channelsMonthData[$ch][] = $chData;
                    }
                }
            }
        }
        ksort($channelsMonthData);
    }

    if (!empty($channelsMonthData)) {
        $totalChannels = count($channelsMonthData);
        foreach ($channelsMonthData as $chNum => $records) {
            usort($records, function($a, $b) {
                return strtotime(extract_record_date($b)) - strtotime(extract_record_date($a));
            });

            $offset = (float) ($initialValues[$chNum] ?? $initialValues[(string)$chNum] ?? 0);

            $latestInMonth = reset($records);
            $earliestInMonth = end($records);

            $rawValEnd = extract_record_value($latestInMonth);
            $valEnd = $rawValEnd !== null ? round($rawValEnd + $offset, 4) : null;
            $dateEnd = extract_record_date($latestInMonth);
            $dateEndStr = $dateEnd ? date('d.m.Y H:i', strtotime($dateEnd)) : '—';

            $rawValStart = extract_record_value($earliestInMonth);
            $valStart = $rawValStart !== null ? round($rawValStart + $offset, 4) : null;
            $dateStart = extract_record_date($earliestInMonth);
            $dateStartStr = $dateStart ? date('d.m.Y H:i', strtotime($dateStart)) : '—';

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
            $lastCons = get_meter_consumption_info($config, $deviceId, (int)$chNum, $valEnd, $dateEnd, $records);
            if ($lastCons && !empty($lastCons['last_change_date'])) {
                $lines[] = "\n  ℹ️ Последний расход зафиксирован: " . date('d.m.Y', strtotime($lastCons['last_change_date'])) . " (на " . round((float) $lastCons['last_change_diff'], 4) . " m³)";
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

function build_device_keyboard(string $serialOrId, bool $isAdded = false): array
{
    $addRemoveBtn = $isAdded
        ? ['text' => '❌ Удалить счетчик', 'callback_data' => 'del_' . $serialOrId]
        : ['text' => '➕ Сохранить в Мои счетчики', 'callback_data' => 'add_' . $serialOrId];

    return [
        'inline_keyboard' => [
            [
                ['text' => '📅 Архив за месяц', 'callback_data' => 'month_' . $serialOrId],
            ],
            [
                $addRemoveBtn
            ]
        ]
    ];
}

function user_meters_list(array $config, string $chatId): string
{
    $meters = get_user_meters($chatId);
    if (empty($meters)) {
        return "У вас пока нет сохраненных счетчиков.\n\nВведите серийный номер прибора или команду:\n<code>/add 8527038</code>";
    }

    $lines = [];
    $lines[] = "📋 <b>Ваши сохраненные счетчики:</b>\n";
    foreach ($meters as $serial => $name) {
        $lines[] = "• <b>{$name}</b> (серийный №: <code>{$serial}</code>)";
    }
    $lines[] = "\nНажмите на кнопку с именем счетчика внизу или введите его серийный номер.";
    return implode("\n", $lines);
}

function devices_list(array $config): string
{
    $lines = [];
    $lines[] = "\xF0\x9F\x9A\x80 <b>Доступные устройства:</b>";
    foreach ($config['devices'] as $id => $info) {
        $lines[] = "<code>{$id}</code> — {$info['name']}";
    }
    $lines[] = "\nВведите числовой ID устройства (серийный номер), чтобы получить данные.";
    return implode("\n", $lines);
}

/* ==================== main (long polling & webhook) ==================== */

function handle_update(array $update, array $config): void
{
    // Обработка Callback Query (нажатие инлайн-кнопок)
    if (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        $cbId = $cb['id'];
        $chatId = (string) $cb['message']['chat']['id'];
        $token = $config['telegram_token'];
        $data = $cb['data'] ?? '';

        if (str_starts_with($data, 'month_')) {
            answer_callback_query($cbId, $token);
            $serial = str_replace('month_', '', $data);
            $device = device_lookup($config, $serial);
            if ($device) {
                $monthReport = build_month_report($config, $device);
                send_message($chatId, $monthReport, $token);
            } else {
                send_message($chatId, "Устройство не найдено.", $token);
            }
            return;
        }

        if (str_starts_with($data, 'add_')) {
            $serial = str_replace('add_', '', $data);
            $device = device_lookup($config, $serial);
            if ($device) {
                add_user_meter($chatId, $serial, $device['name']);
                answer_callback_query($cbId, $token, "Счетчик {$serial} добавлен!");
                $replyKey = build_main_reply_keyboard($chatId);
                send_message($chatId, "✅ Счетчик <b>{$device['name']}</b> ({$serial}) добавлен в меню «📋 Мои счетчики».", $token, $replyKey);
            } else {
                answer_callback_query($cbId, $token, "Не удалось найти счетчик.");
            }
            return;
        }

        if (str_starts_with($data, 'del_')) {
            $serial = str_replace('del_', '', $data);
            remove_user_meter($chatId, $serial);
            answer_callback_query($cbId, $token, "Счетчик {$serial} удален!");
            $replyKey = build_main_reply_keyboard($chatId);
            send_message($chatId, "🗑 Счетчик <code>{$serial}</code> удален из ваших приборов.", $token, $replyKey);
            return;
        }
    }

    $message = $update['message'] ?? null;
    if (!$message || empty($message['text'])) {
        return;
    }

    $chatId = (string) $message['chat']['id'];
    $token = $config['telegram_token'];
    $text = trim($message['text']);
    $mainKey = build_main_reply_keyboard($chatId);

    if ($text === '/start' || $text === '/help') {
        send_message($chatId, TO_CMD, $token, $mainKey);
        return;
    }

    if ($text === '/my' || $text === '📋 Мои счетчики') {
        send_message($chatId, user_meters_list($config, $chatId), $token, $mainKey);
        return;
    }

    if ($text === '➕ Добавить счетчик') {
        send_message($chatId, "Чтобы зарегистрировать новый прибор в боте, отправьте команду:\n<code>/add ID UUID</code>\n\nПример:\n<code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f</code>", $token, $mainKey);
        return;
    }

    // Регистрация нового прибора через команду: /add ID UUID (например /add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f)
    if (str_starts_with($text, '/add ')) {
        $parts = preg_split('/\s+/', trim($text));
        // $parts[0] = '/add', $parts[1] = serial/id, $parts[2] = uuid
        if (count($parts) >= 3) {
            $serial = $parts[1];
            $uuid = $parts[2];
            $name = count($parts) >= 4 ? implode(' ', array_slice($parts, 3)) : "Счетчик {$serial}";

            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                send_message($chatId, "❌ Неверный формат UUID. Укажите корректный UUID прибора.", $token, $mainKey);
                return;
            }

            register_custom_device($serial, $uuid, $name);
            fetch_and_save_initial_values($config, $serial, $uuid);
            add_user_meter($chatId, $serial, $name);
            $newKey = build_main_reply_keyboard($chatId);

            send_message($chatId, "🎉 Новый прибор успешно зарегистрирован!\n\n• <b>ID / Серийный №</b>: <code>{$serial}</code>\n• <b>UUID</b>: <code>{$uuid}</code>\n\nОн также автоматически сохранен в ваше меню «📋 Мои счетчики».", $token, $newKey);
        } else {
            send_message($chatId, "Неверный формат команды.\n\nИспользование:\n<code>/add ID UUID</code>\n\nПример:\n<code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f</code>", $token, $mainKey);
        }
        return;
    }

    if (str_starts_with($text, '/del ')) {
        $serial = trim(substr($text, 5));
        remove_user_meter($chatId, $serial);
        $newKey = build_main_reply_keyboard($chatId);
        send_message($chatId, "🗑 Счетчик <code>{$serial}</code> удален из списка ваших сохраненных приборов.", $token, $newKey);
        return;
    }

    // Задать/изменить начальные показания: /init СЕРИЙНЫЙ_№ КАНАЛ ЗНАЧЕНИЕ (например /init 8527038 1 0.12)
    if (str_starts_with($text, '/init ') || str_starts_with($text, '/set ')) {
        $parts = preg_split('/\s+/', trim($text));
        if (count($parts) >= 4) {
            $serial = $parts[1];
            $chNum = (int) $parts[2];
            $val = (float) str_replace(',', '.', $parts[3]);

            $customDevices = load_registered_devices();
            if (!isset($customDevices[(int) $serial])) {
                $dev = device_lookup($config, $serial);
                if ($dev) {
                    register_custom_device($serial, $dev['device_id'], $dev['name']);
                    $customDevices = load_registered_devices();
                }
            }

            if (isset($customDevices[(int) $serial])) {
                if (!isset($customDevices[(int) $serial]['initial_values'])) {
                    $customDevices[(int) $serial]['initial_values'] = [];
                }
                $customDevices[(int) $serial]['initial_values'][(string) $chNum] = $val;
                atomic_write_json(custom_devices_file(), $customDevices);

                // Очищаем локальный кэш расхода, чтобы пересчитать с новым начальным значением
                $cache = load_meter_cache();
                $devId = $customDevices[(int) $serial]['device_id'] ?? '';
                if ($devId && isset($cache[$devId]['channels'][$chNum])) {
                    unset($cache[$devId]['channels'][$chNum]);
                    save_meter_cache($cache);
                }

                send_message($chatId, "✅ Начальное показание для прибора <code>{$serial}</code> (Канал {$chNum}) успешно установлено: <b>{$val} m³</b>.", $token, $mainKey);
            } else {
                send_message($chatId, "❌ Прибор с серийным номером <code>{$serial}</code> не найден.", $token, $mainKey);
            }
        } else {
            send_message($chatId, "Использование команды:\n<code>/init СЕРИЙНЫЙ_№ КАНАЛ ЗНАЧЕНИЕ</code>\n\nПример:\n<code>/init 8527038 1 0.12</code>\n<code>/init 8524390 1 0.06</code>", $token, $mainKey);
        }
        return;
    }

    // Клик по кнопке постоянной клавиатуры типа "💧 Fluo (8527038)"
    if (preg_match('/\((\d+)\)$/', $text, $matches)) {
        $text = $matches[1];
    }

    $device = device_lookup($config, $text);
    if (!$device) {
        send_message($chatId, "Устройство не найдено.\n\n" . TO_CMD, $token, $mainKey);
        return;
    }

    $serial = $device['serial_number'] ?? $text;
    $userMeters = get_user_meters($chatId);
    $isAdded = isset($userMeters[$serial]);

    $report = build_report($config, $device);
    $keyboard = build_device_keyboard($serial, $isAdded);

    send_message($chatId, $report, $token, $keyboard);
}

function check_config(array $config): void
{
    $missing = [];
    if ($config['telegram_token'] === '') {
        $missing[] = 'TELEGRAM_BOT_TOKEN';
    }
    if ($config['unicboard_token'] === '') {
        $missing[] = 'UNICBOARD_API_TOKEN';
    }
    if ($missing) {
        fwrite(STDERR, "Ошибка: задайте в .env: " . implode(', ', $missing) . "\n");
        exit(1);
    }
}

/** Режим Webhook. Запуск на веб-сервере. */
function run_webhook(array $config): void
{
    check_config($config);

    $secret = $config['webhook_secret'] ?? '';
    if ($secret !== '' && ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== $secret) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $raw = file_get_contents('php://input');
    $update = json_decode((string) $raw, true);
    if (is_array($update)) {
        handle_update($update, $config);
    }
    http_response_code(200);
    echo 'ok';
}

/** Режим Long-polling. Запуск из CLI. */
function run_polling(array $config): void
{
    check_config($config);
    $offset = 0;
    echo "Бот запущен (long-polling). Нажмите Ctrl+C для остановки.\n";

    while (true) {
        $url = "https://api.telegram.org/bot{$config['telegram_token']}/getUpdates?timeout=50&offset={$offset}";
        [$code, $resp] = http_get($url, [], 60, 10);
        if ($code !== 200 || !($resp['ok'] ?? false)) {
            sleep(2);
            continue;
        }

        foreach ($resp['result'] ?? [] as $update) {
            $updateId = $update['update_id'] ?? null;
            if ($updateId !== null && $updateId >= $offset) {
                $offset = $updateId + 1;
            }
            handle_update($update, $config);
        }
    }
}

if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    // Режим командной строки: php bot.php
    if (isset($argv[1]) && $argv[1] === 'webhook') {
        run_webhook($config);
    } else {
        run_polling($config);
    }
} else {
    // Веб-режим: webhook через $_GET['webhook'] или обычный запрос
    run_webhook($config);
}