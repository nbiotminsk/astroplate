<?php
/*
 * Конфигурация бота UnicBoard.
 *
 * Секреты (токены) читаются из файла .env — см. .env.example.
 * Список устройств задаётся ниже, в разделе 'devices'.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';
load_env(__DIR__ . '/.env');

$timezone = getenv('BOT_TIMEZONE') ?: 'Europe/Minsk';
date_default_timezone_set($timezone);

$logFile = getenv('BOT_LOG_FILE') ?: (__DIR__ . '/storage/bot.log');
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@ini_set('log_errors', '1');
@ini_set('error_log', $logFile);

return [
    // Часовой пояс бота (по умолчанию Europe/Minsk, UTC+3)
    'timezone' => $timezone,

    // Путь к файлу логов бота (по умолчанию storage/bot.log)
    'log_file' => $logFile,

    // Токен бота из @BotFather (переменная TELEGRAM_BOT_TOKEN)
    'telegram_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',

    // Токен API UnicBoard (переменная UNICBOARD_API_TOKEN)
    'unicboard_token' => getenv('UNICBOARD_API_TOKEN') ?: '',

    // Базовый URL API UnicBoard
    'unicboard_api_base' => getenv('UNICBOARD_API_BASE') ?: 'https://api.public.data-aggregator.unicboard.by',

    // Секретный токен Webhook (переменная TELEGRAM_WEBHOOK_SECRET)
    'webhook_secret' => getenv('TELEGRAM_WEBHOOK_SECRET') ?: '',

    // Включение расширенной диагностики API (по умолчанию включено)
    'enable_diagnostics' => filter_var(getenv('ENABLE_DIAGNOSTICS') ?: 'true', FILTER_VALIDATE_BOOLEAN),

    /* Список устройств по умолчанию (опционально) */
    'devices' => [],
];