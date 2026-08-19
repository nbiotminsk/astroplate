<?php

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

// Дергаем API UnicBoard, чтобы он "проснулся"
UnicBoard::getAllDevices($config, 1);

echo "API PING OK\n";
