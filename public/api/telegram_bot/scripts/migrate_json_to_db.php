<?php

declare(strict_types=1);

namespace TelegramBot\Scripts;

use TelegramBot\Database;
use TelegramBot\Repository\SqlDeviceRepository;
use TelegramBot\Repository\SqlMeterCacheRepository;
use TelegramBot\Repository\SqlUserMeterRepository;
use TelegramBot\Storage;

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

$config = require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "🚀 Запуск миграции данных из JSON в MariaDB (база: " . ($config['database']['database'] ?? 'teleofis_24') . ")...\n";

$pdo = Database::getConnection($config);
if (!$pdo) {
    echo "❌ Ошибка подключения к базе данных. Проверьте параметры в config.php.\n";
    exit(1);
}

echo "✅ База данных подключена успешно! Таблицы инициализированы.\n";

$sqlDevRepo = new SqlDeviceRepository($config);
$sqlUserRepo = new SqlUserMeterRepository($config);
$sqlCacheRepo = new SqlMeterCacheRepository($config);

// 1. Миграция устройств (registered_devices.json)
$devFile = Storage::customDevicesFile();
$devCount = 0;
if (file_exists($devFile)) {
    $devices = json_decode((string) file_get_contents($devFile), true) ?: [];
    foreach ($devices as $serial => $dev) {
        $sqlDevRepo->registerDevice(
            serial: (string) $serial,
            uuid: (string) ($dev['device_id'] ?? ''),
            name: (string) ($dev['name'] ?? $serial),
            initialValues: (array) ($dev['initial_values'] ?? []),
            address: !empty($dev['address']) ? (string) $dev['address'] : null,
            activeChannels: isset($dev['active_channels']) && is_array($dev['active_channels']) ? $dev['active_channels'] : [1, 2],
            channels: isset($dev['channels']) && is_array($dev['channels']) ? $dev['channels'] : []
        );
        $devCount++;
    }
}
echo "📦 Устройства перенесены: {$devCount}\n";

// 2. Миграция пользователей и привязок (user_meters.json)
$userFile = Storage::userStorageFile();
$userMeterCount = 0;
if (file_exists($userFile)) {
    $userMeters = json_decode((string) file_get_contents($userFile), true) ?: [];
    foreach ($userMeters as $chatId => $meters) {
        if (is_array($meters)) {
            foreach ($meters as $serial => $info) {
                $name = is_array($info) ? ($info['name'] ?? (string) $serial) : (string) $info;
                $devId = is_array($info) ? ($info['device_id'] ?? '') : '';
                $addr = is_array($info) ? ($info['address'] ?? null) : null;
                $sqlUserRepo->addMeter((string) $chatId, (string) $serial, $name, $devId, $addr);
                $userMeterCount++;
            }
        }
    }
}
echo "👥 Привязки пользователей перенесены: {$userMeterCount}\n";

// 3. Миграция кэша расхода (meter_cache.json)
$cacheFile = Storage::meterCacheFile();
$cacheCount = 0;
if (file_exists($cacheFile)) {
    $cacheData = json_decode((string) file_get_contents($cacheFile), true) ?: [];
    if (!empty($cacheData)) {
        $sqlCacheRepo->saveCache($cacheData);
        $cacheCount = count($cacheData);
    }
}
echo "⚡ Записи кэша перенесены: {$cacheCount}\n";

// 4. Миграция состояний пользователей (user_states.json)
$statesFile = Storage::userStatesFile();
$statesCount = 0;
if (file_exists($statesFile)) {
    $statesData = json_decode((string) file_get_contents($statesFile), true) ?: [];
    foreach ($statesData as $chatId => $state) {
        if (is_array($state)) {
            $sqlUserRepo->setUserState((string) $chatId, $state);
            $statesCount++;
        }
    }
}
echo "📝 Состояния пользователей перенесены: {$statesCount}\n";

// 5. Очистка пустых / невалидных записей
$delUserDev = $pdo->exec("DELETE FROM user_devices WHERE serial_number IS NULL OR TRIM(serial_number) = '' OR serial_number = '0'");
$delDev = $pdo->exec("DELETE FROM devices WHERE serial_number IS NULL OR TRIM(serial_number) = '' OR serial_number = '0'");
echo "🧹 Очищено пустых/невалидных записей: " . ($delUserDev + $delDev) . "\n";

echo "🎉 Миграция успешно завершена!\n";
