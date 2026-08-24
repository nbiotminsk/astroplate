<?php

declare(strict_types=1);

namespace TelegramBot\Scripts;

use TelegramBot\Database;
use TelegramBot\Storage;

spl_autoload_register(static function (string $class): void {
    $prefix = 'TelegramBot\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

$config = require __DIR__ . '/../config.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = Database::getConnection($config);
if (!$pdo) {
    echo "DB Connection failed\n";
    exit;
}

echo "=== USERS ===\n";
$users = $pdo->query("SELECT * FROM users")->fetchAll(\PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== USER_DEVICES ===\n";
$uds = $pdo->query("SELECT * FROM user_devices")->fetchAll(\PDO::FETCH_ASSOC);
echo json_encode($uds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== DEVICES ===\n";
$devs = $pdo->query("SELECT * FROM devices")->fetchAll(\PDO::FETCH_ASSOC);
echo json_encode($devs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== RECENT READINGS ===\n";
$readings = $pdo->query("SELECT * FROM meter_readings ORDER BY id DESC LIMIT 10")->fetchAll(\PDO::FETCH_ASSOC);
echo json_encode($readings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== RECENT BOT LOGS ===\n";
$logFile = Storage::logFile();
if (file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -20);
    echo implode("", $lastLines);
} else {
    echo "No log file found.\n";
}
