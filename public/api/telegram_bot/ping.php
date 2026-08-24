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

// Запрашиваем UnicBoard API для «пробуждения» и проверки доступности сервера
// Быстрый эндпоинт мониторинга работоспособности бота и связи с UnicBoard
$startTs = microtime(true);
$res = UnicBoard::getAllDevices($config, 1, 5, 2, 2000000);
$durationMs = round((microtime(true) - $startTs) * 1000, 1);

$httpCode = $res['http_status'] ?? 0;
$isOk = ($res['ok'] ?? false) || ($httpCode >= 200 && $httpCode < 300);

if ($isOk) {
    $logMsg = "PING OK: Server responded in {$durationMs} ms (HTTP {$httpCode})";
    Storage::log($logMsg);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
    }
    echo "API PING OK (HTTP {$httpCode}, {$durationMs} ms)\n";
} else {
    $err = !empty($res['errors']) ? json_encode($res['errors'], JSON_UNESCAPED_UNICODE) : 'Timeout / No connection';
    $logMsg = "PING FAILED: Server error (HTTP {$httpCode}, {$durationMs} ms) - {$err}";
    Storage::log($logMsg);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(502);
    }
    echo "API PING FAILED (HTTP {$httpCode}, {$durationMs} ms): {$err}\n";
}
