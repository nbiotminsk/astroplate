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

namespace TelegramBot;

$config = require __DIR__ . '/config.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'TelegramBot\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

$container = new Container($config);
$handler = new BotHandler($container);

if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    // Режим командной строки: php bot.php
    if (isset($argv[1]) && $argv[1] === 'webhook') {
        $handler->runWebhook();
    } else {
        $handler->runPolling();
    }
} else {
    // Веб-режим: webhook
    $handler->runWebhook();
}