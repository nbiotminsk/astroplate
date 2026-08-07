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
Команды:
/devices — список доступных устройств
Или просто введите числовой ID устройства, например:
8527038
TXT;

/* ==================== HTTP helpers ==================== */

function http_get(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, json_decode((string)$body, true)];
}

function http_post_json(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(
            ['Content-Type: application/json'],
            $headers
        ),
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, json_decode((string)$data, true)];
}

/* ==================== UnicBoard API ==================== */

function unicboard_headers(array $config): array
{
    return ['Authorization: Bearer ' . $config['unicboard_token']];
}

/**
 * Полные показания по одному device_id через POST /api/v1/devices/values
 */
function get_device_values(array $config, string $deviceUuid, int $limit = 1): array
{
    $url  = $config['unicboard_api_base'] . '/api/v1/devices/values?limit=' . $limit;
    $body = ['devices_id' => [$deviceUuid]];
    [$code, $resp] = http_post_json($url, $body, unicboard_headers($config));

    return [
        'http_code' => $code,
        'payload'   => $resp['payload'] ?? [],
        'errors'    => $resp['errors'] ?? [],
        'ok'        => $resp['ok'] ?? false,
    ];
}

/** Температура прибора */
function get_temperature(array $config, string $deviceId, int $limit = 1): ?array
{
    $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/temperatures?limit=' . $limit;
    [$code, $resp] = http_get($url, unicboard_headers($config));
    if ($code !== 200 || !isset($resp['payload'][0])) {
        return null;
    }
    return $resp['payload'][0];
}

/** Уровень батареи прибора */
function get_battery(array $config, string $deviceId, int $limit = 1): ?array
{
    $url = $config['unicboard_api_base'] . '/api/v1/devices/' . $deviceId . '/battery-level?limit=' . $limit;
    [$code, $resp] = http_get($url, unicboard_headers($config));
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

function send_message(string $chatId, string $text, string $token): void
{
    tg_api('sendMessage', [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ], $token);
}

/* ==================== Логика бота ==================== */

function device_lookup(array $config, string $input): ?array
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    // Ищем по числовому ID
    if (isset($config['devices'][(int)$input])) {
        return $config['devices'][(int)$input];
    }
    // Ищем по названию (без учёта регистра)
    foreach ($config['devices'] as $info) {
        if (mb_strtolower($info['name'], 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
            return $info;
        }
    }
    return null;
}

function build_report(array $config, array $device): string
{
    $name     = $device['name'];
    $deviceId = $device['device_id'];

    $lines   = [];
    $lines[] = "\xF0\x9F\x93\xB1 <b>{$name}</b>";

    // Показания
    $values = get_device_values($config, $deviceId, 1);
    if (!empty($values['payload'][0])) {
        $v = $values['payload'][0];
        $lastVal       = $v['last_value'] ?? null;
        $lastValDate   = $v['last_value_date'] ?? null;
        $dateStr       = $lastValDate ? date('d.m.Y H:i', strtotime($lastValDate)) : '—';
        $lines[] = "\xF0\x9F\x93\x8A Последние показания: <b>" . ($lastVal !== null ? $lastVal : '—') . '</b>';
        $lines[] = "\xE2\x8F\xB0 Дата показаний: <b>" . $dateStr . '</b>';
    } else {
        $lines[] = "\xF0\x9F\x93\x8A Показания: нет данных";
    }

    // Температура
    $temp = get_temperature($config, $deviceId, 1);
    if ($temp !== null) {
        $t = $temp['value'] ?? null;
        $lines[] = "\xF0\x9F\x92\xA8 Температура: <b>" . ($t !== null ? $t : '—') . " °C</b>";
    } else {
        $lines[] = "\xF0\x9F\x92\xA8 Температура: нет данных";
    }

    // Батарея
    $bat = get_battery($config, $deviceId, 1);
    if ($bat !== null) {
        $b = $bat['value'] ?? null;
        $lines[] = "\xF0\x9F\x94\x8B Батарея: <b>" . ($b !== null ? $b : '—') . " V</b>";
    } else {
        $lines[] = "\xF0\x9F\x94\x8B Батарея: нет данных";
    }

    if (empty($values['payload'][0]) && $temp === null && $bat === null) {
        $lines[] = "\n\xE2\x9A\xA0\xEF\xB8\x8F Не удалось получить данные по устройству {$deviceId}.";
    }

    return implode("\n", $lines);
}

function devices_list(array $config): string
{
    $lines = [];
    $lines[] = "\xF0\x9F\x9A\x80 <b>Доступные устройства:</b>";
    foreach ($config['devices'] as $id => $info) {
        $lines[] = "<code>{$id}</code> — {$info['name']}";
    }
    $lines[] = "\nВведите число, чтобы получить данные.";
    return implode("\n", $lines);
}

/* ==================== main (long polling) ==================== */

function handle_update(array $update, array $config): void
{
    $message = $update['message'] ?? null;
    if (!$message || empty($message['text'])) {
        return;
    }

    $chatId  = (string)$message['chat']['id'];
    $token   = $config['telegram_token'];
    $text    = trim($message['text']);

    if ($text === '/start' || $text === '/help') {
        send_message($chatId, TO_CMD, $token);
        return;
    }

    if ($text === '/devices') {
        send_message($chatId, devices_list($config), $token);
        return;
    }

    $device = device_lookup($config, $text);
    if (!$device) {
        send_message($chatId, "Устройство не найдено.\n\n" . TO_CMD, $token);
        return;
    }

    $report = build_report($config, $device);
    send_message($chatId, $report, $token);
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
    $raw    = file_get_contents('php://input');
    $update = json_decode((string)$raw, true);
    if (is_array($update)) {
        handle($update, $config);
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
        [$code, $resp] = http_get($url);
        if ($code !== 200 || !($resp['ok'] ?? false)) {
            sleep(2);
            continue;
        }

        foreach ($resp['result'] ?? [] as $update) {
            $updateId = $update['update_id'] ?? null;
            if ($updateId !== null && $updateId >= $offset) {
                $offset = $updateId + 1;
            }
            handle($update, $config);
        }
    }
}

if (PHP_SAPI === 'cli') {
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