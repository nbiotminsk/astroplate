<?php

declare(strict_types=1);

namespace TelegramBot;

use PDO;
use PDOException;
use Throwable;

class Database
{
    private static ?PDO $pdo = null;
    private static bool $migrated = false;
    private static bool $connectionFailed = false;

    public static function getConnection(array $config = []): ?PDO
    {
        if (self::$connectionFailed) {
            return null;
        }

        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dbCfg = $config['database'] ?? [];
        if (empty($dbCfg)) {
            $fullConfig = require __DIR__ . '/../config.php';
            $dbCfg = $fullConfig['database'] ?? [];
        }

        $driver = $dbCfg['driver'] ?? 'mysql';
        $host = $dbCfg['host'] ?? 'localhost';
        $port = (int) ($dbCfg['port'] ?? 3306);
        $dbname = $dbCfg['database'] ?? 'teleofis_24';
        $user = $dbCfg['username'] ?? 'teleofis';
        $pass = $dbCfg['password'] ?? '';
        $charset = $dbCfg['charset'] ?? 'utf8mb4';

        $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 3,
            ]);

            self::$pdo = $pdo;

            if (!self::$migrated) {
                self::autoMigrate($pdo);
                self::$migrated = true;
            }

            return self::$pdo;
        } catch (Throwable $e) {
            self::$connectionFailed = true;
            Storage::log('DB Connection failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$connectionFailed = ($pdo === null);
        self::$migrated = false;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$connectionFailed = false;
        self::$migrated = false;
    }

    public static function isConnected(array $config = []): bool
    {
        return self::getConnection($config) !== null;
    }

    public static function autoMigrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $queries = [
                // 1. Таблица пользователей
                "CREATE TABLE IF NOT EXISTS users (
                    chat_id VARCHAR(64) PRIMARY KEY,
                    username VARCHAR(128) NULL,
                    first_name VARCHAR(128) NULL,
                    last_name VARCHAR(128) NULL,
                    state_json TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

                // 2. Таблица приборов
                "CREATE TABLE IF NOT EXISTS devices (
                    serial_number VARCHAR(64) PRIMARY KEY,
                    device_id VARCHAR(64) NOT NULL,
                    name VARCHAR(255) NULL,
                    address VARCHAR(255) NULL,
                    active_channels VARCHAR(64) NULL,
                    channels_config TEXT NULL,
                    is_fluo TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_device_id (device_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

                // 3. Связь пользователей и их приборов
                "CREATE TABLE IF NOT EXISTS user_devices (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    chat_id VARCHAR(64) NOT NULL,
                    serial_number VARCHAR(64) NOT NULL,
                    custom_name VARCHAR(255) NULL,
                    custom_address VARCHAR(255) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_user_device (chat_id, serial_number),
                    INDEX idx_chat_id (chat_id),
                    INDEX idx_serial (serial_number)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

                // 4. Точки истории и архивы показаний
                "CREATE TABLE IF NOT EXISTS meter_readings (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    device_id VARCHAR(64) NOT NULL,
                    channel_number INT NOT NULL,
                    reading_date DATETIME NOT NULL,
                    value DOUBLE NOT NULL,
                    value_raw DOUBLE NULL,
                    value_type VARCHAR(32) DEFAULT 'DEVICE_DATA',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_reading (device_id, channel_number, reading_date),
                    INDEX idx_lookup (device_id, channel_number, reading_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

                // 5. Снапшот последних онлайн-данных прибора
                "CREATE TABLE IF NOT EXISTS device_info_cache (
                    device_id VARCHAR(64) PRIMARY KEY,
                    payload_json MEDIUMTEXT NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

                // 6. Кэш суточного расхода
                "CREATE TABLE IF NOT EXISTS meter_cache (
                    cache_key VARCHAR(128) PRIMARY KEY,
                    data_json TEXT NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
            ];

            foreach ($queries as $sql) {
                $pdo->exec($sql);
            }
        } elseif ($driver === 'sqlite') {
            // Для изолированного unit-тестирования в памяти
            $sqliteQueries = [
                "CREATE TABLE IF NOT EXISTS users (
                    chat_id TEXT PRIMARY KEY,
                    username TEXT NULL,
                    first_name TEXT NULL,
                    last_name TEXT NULL,
                    state_json TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );",
                "CREATE TABLE IF NOT EXISTS devices (
                    serial_number TEXT PRIMARY KEY,
                    device_id TEXT NOT NULL,
                    name TEXT NULL,
                    address TEXT NULL,
                    active_channels TEXT NULL,
                    channels_config TEXT NULL,
                    is_fluo INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );",
                "CREATE TABLE IF NOT EXISTS user_devices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    chat_id TEXT NOT NULL,
                    serial_number TEXT NOT NULL,
                    custom_name TEXT NULL,
                    custom_address TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (chat_id, serial_number)
                );",
                "CREATE TABLE IF NOT EXISTS meter_readings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    device_id TEXT NOT NULL,
                    channel_number INTEGER NOT NULL,
                    reading_date DATETIME NOT NULL,
                    value REAL NOT NULL,
                    value_raw REAL NULL,
                    value_type TEXT DEFAULT 'DEVICE_DATA',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (device_id, channel_number, reading_date)
                );",
                "CREATE TABLE IF NOT EXISTS device_info_cache (
                    device_id TEXT PRIMARY KEY,
                    payload_json TEXT NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );",
                "CREATE TABLE IF NOT EXISTS meter_cache (
                    cache_key TEXT PRIMARY KEY,
                    data_json TEXT NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );",
            ];

            foreach ($sqliteQueries as $sql) {
                $pdo->exec($sql);
            }
        }
    }
}
