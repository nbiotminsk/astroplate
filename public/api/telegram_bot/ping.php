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

use TelegramBot\Repository\ReadingRepository;

// Запрашиваем UnicBoard API для «пробуждения», проверки связи и сбора данных
$startTs = microtime(true);
$res = UnicBoard::getAllDevices($config, 1, 100, 2, 3000000);
$durationMs = round((microtime(true) - $startTs) * 1000, 1);

$httpCode = $res['http_status'] ?? 0;
$isOk = ($res['ok'] ?? false) || ($httpCode >= 200 && $httpCode < 300);

// Проверяем статус базы данных MariaDB и синхронизируем показания
$dbMsg = '';
$syncMsg = '';
$pdo = Database::getConnection($config);
$readingRepo = new ReadingRepository($config);

if ($pdo) {
    try {
        $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
        $dbMsg = "DB OK (MariaDB {$ver})";

        // Если ответ API успешен — синхронизируем свежие показания в БД
        if ($isOk && !empty($res['payload']) && is_array($res['payload'])) {
            $devices = $res['payload'];
            $devicesCount = count($devices);
            $newReadingsCount = 0;
            $updatedSnapshots = 0;

            foreach ($devices as $dev) {
                $deviceId = (string) ($dev['id'] ?? '');
                if ($deviceId === '') {
                    continue;
                }

                // 1. Сохраняем свежий снимок устройства для быстрого офлайн-доступа
                $readingRepo->saveDeviceInfoSnapshot($deviceId, $dev);
                $updatedSnapshots++;

                // 2. Извлекаем текущие показания каналов
                $readings = MeterService::extractCurrentReadingsFromDeviceInfo($dev);
                foreach ($readings as $chNum => $reading) {
                    $lastVal = $reading->lastValue;
                    $lastValDate = $reading->lastValueDate;

                    if ($lastVal === null || $lastValDate === null) {
                        continue;
                    }

                    $formattedDate = date('Y-m-d H:i:s', MeterService::parseUtcTimestamp((string) $lastValDate));

                    // Проверяем, есть ли уже запись с такой датой
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM meter_readings 
                        WHERE device_id = :dev_id AND channel_number = :ch_num AND reading_date = :r_date 
                        LIMIT 1
                    ");
                    $checkStmt->execute([
                        ':dev_id' => $deviceId,
                        ':ch_num' => (int) $chNum,
                        ':r_date' => $formattedDate,
                    ]);

                    if (!$checkStmt->fetchColumn()) {
                        // Новое показание -> сохраняем в историю
                        $insertStmt = $pdo->prepare("
                            INSERT INTO meter_readings (device_id, channel_number, reading_date, value, value_raw, value_type)
                            VALUES (:dev_id, :ch_num, :r_date, :val, :val_raw, 'DEVICE_DATA')
                        ");
                        $insertStmt->execute([
                            ':dev_id' => $deviceId,
                            ':ch_num' => (int) $chNum,
                            ':r_date' => $formattedDate,
                            ':val' => (float) $lastVal,
                            ':val_raw' => (float) $lastVal,
                        ]);
                        $newReadingsCount++;
                    }
                }
            }

            $syncMsg = "SYNC: Опрошено приборов: {$devicesCount}, новых показаний записано в БД: {$newReadingsCount}";
        }
    } catch (\Throwable $e) {
        $dbMsg = "DB Error: " . $e->getMessage();
    }
} else {
    $dbMsg = "DB: JSON fallback active";
}

$statusOutput = "API PING OK (HTTP {$httpCode}, {$durationMs} ms)\n{$dbMsg}";
if (!empty($syncMsg)) {
    $statusOutput .= "\n{$syncMsg}";
}

if ($isOk) {
    $logMsg = "PING OK: Server responded in {$durationMs} ms (HTTP {$httpCode}), {$dbMsg}" . (!empty($syncMsg) ? ", {$syncMsg}" : '');
    Storage::log($logMsg);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
    }
    echo "{$statusOutput}\n";
} else {
    $err = !empty($res['errors']) ? json_encode($res['errors'], JSON_UNESCAPED_UNICODE) : 'Timeout / No connection';
    $logMsg = "PING FAILED: Server error (HTTP {$httpCode}, {$durationMs} ms) - {$err}, {$dbMsg}";
    Storage::log($logMsg);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(502);
    }
    echo "API PING FAILED (HTTP {$httpCode}, {$durationMs} ms): {$err}\n{$dbMsg}\n";
}
