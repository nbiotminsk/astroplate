<?php

declare(strict_types=1);

/**
 * Тест отчёта для конкретного прибора БЕЗ кэша.
 * Запуск: php tests/test_device_report.php 8554760
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'TelegramBot\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use TelegramBot\MeterService;
use TelegramBot\ReportService;

$config = require __DIR__ . '/../config.php';
$serial = $argv[1] ?? '8554760';

// Отключаем кэш глобально
MeterService::$disableCache = true;
echo "🚫 Кэш отключён (MeterService::\$disableCache = true)\n\n";

$device = MeterService::deviceLookup($config, $serial);
if (!$device) {
    echo "❌ Устройство '{$serial}' не найдено.\n";
    exit(1);
}

echo "🔍 Устройство: {$device->name} (serial={$serial}, id={$device->deviceId})\n";
echo "📦 initial_values: " . json_encode($device->initialValues) . "\n\n";

echo "--- buildReport ---\n";
$report = ReportService::buildReport($config, $device);
echo strip_tags($report) . "\n";

echo "\n--- buildMonthReport ---\n";
$monthReport = ReportService::buildMonthReport($config, $device);
echo strip_tags($monthReport) . "\n";
