<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use PDO;
use TelegramBot\Database;
use TelegramBot\Storage;
use Throwable;

class SqlUserMeterRepository implements UserMeterRepositoryInterface
{
    private array $config;
    private JsonUserMeterRepository $fallbackJsonRepo;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->fallbackJsonRepo = new JsonUserMeterRepository();
    }

    private function getPdo(): ?PDO
    {
        return Database::getConnection($this->config);
    }

    public function getMetersByChatId(string $chatId): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return $this->fallbackJsonRepo->getMetersByChatId($chatId);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT ud.serial_number, ud.custom_name, ud.custom_address,
                       d.name AS dev_name, d.address AS dev_address, d.device_id
                FROM user_devices ud
                LEFT JOIN devices d ON ud.serial_number = d.serial_number
                WHERE ud.chat_id = :chat_id
                ORDER BY ud.id ASC
            ");
            $stmt->execute([':chat_id' => $chatId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $serial = (string) $row['serial_number'];
                $result[$serial] = [
                    'name' => !empty($row['custom_name']) ? (string) $row['custom_name'] : ((string) ($row['dev_name'] ?? $serial)),
                    'address' => !empty($row['custom_address']) ? (string) $row['custom_address'] : (!empty($row['dev_address']) ? (string) $row['dev_address'] : null),
                    'device_id' => (string) ($row['device_id'] ?? ''),
                ];
            }

            return $result;
        } catch (Throwable $e) {
            Storage::log('SqlUserMeterRepository::getMetersByChatId failed, fallback to JSON: ' . $e->getMessage());
            return $this->fallbackJsonRepo->getMetersByChatId($chatId);
        }
    }

    public function addMeter(string $chatId, string $serial, string $name, string $deviceId = '', ?string $address = null): void
    {
        // Резервное сохранение в JSON
        $this->fallbackJsonRepo->addMeter($chatId, $serial, $name, $deviceId, $address);

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            // 1. Убедимся, что пользователь есть в таблице users
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $userSql = "INSERT INTO users (chat_id) VALUES (:chat_id) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP";
            } else {
                $userSql = "INSERT OR IGNORE INTO users (chat_id) VALUES (:chat_id)";
            }
            $userStmt = $pdo->prepare($userSql);
            $userStmt->execute([':chat_id' => $chatId]);

            // 2. Вставляем или обновляем связь user_devices
            if ($driver === 'mysql') {
                $sql = "INSERT INTO user_devices (chat_id, serial_number, custom_name, custom_address)
                        VALUES (:chat_id, :serial, :name, :address)
                        ON DUPLICATE KEY UPDATE
                            custom_name = VALUES(custom_name),
                            custom_address = VALUES(custom_address)";
            } else {
                $sql = "INSERT OR REPLACE INTO user_devices (chat_id, serial_number, custom_name, custom_address)
                        VALUES (:chat_id, :serial, :name, :address)";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':chat_id' => $chatId,
                ':serial' => $serial,
                ':name' => $name,
                ':address' => $address,
            ]);
        } catch (Throwable $e) {
            Storage::log('SqlUserMeterRepository::addMeter failed: ' . $e->getMessage());
        }
    }

    public function removeMeter(string $chatId, string $serial): void
    {
        $this->fallbackJsonRepo->removeMeter($chatId, $serial);

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM user_devices WHERE chat_id = :chat_id AND serial_number = :serial");
            $stmt->execute([
                ':chat_id' => $chatId,
                ':serial' => $serial,
            ]);
        } catch (Throwable $e) {
            Storage::log('SqlUserMeterRepository::removeMeter failed: ' . $e->getMessage());
        }
    }

    public function getUserState(string $chatId): ?array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return Storage::getUserState($chatId);
        }

        try {
            $stmt = $pdo->prepare("SELECT state_json FROM users WHERE chat_id = :chat_id LIMIT 1");
            $stmt->execute([':chat_id' => $chatId]);
            $raw = $stmt->fetchColumn();

            if ($raw) {
                $decoded = json_decode((string) $raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return Storage::getUserState($chatId);
        } catch (Throwable $e) {
            return Storage::getUserState($chatId);
        }
    }

    public function setUserState(string $chatId, array $state): void
    {
        Storage::setUserState($chatId, $state);

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $json = json_encode($state, JSON_UNESCAPED_UNICODE);
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'mysql') {
                $sql = "INSERT INTO users (chat_id, state_json) VALUES (:chat_id, :state)
                        ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = CURRENT_TIMESTAMP";
            } else {
                $sql = "INSERT OR REPLACE INTO users (chat_id, state_json) VALUES (:chat_id, :state)";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':chat_id' => $chatId,
                ':state' => $json,
            ]);
        } catch (Throwable $e) {
            Storage::log('SqlUserMeterRepository::setUserState failed: ' . $e->getMessage());
        }
    }

    public function clearUserState(string $chatId): void
    {
        Storage::clearUserState($chatId);

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $stmt = $pdo->prepare("UPDATE users SET state_json = NULL WHERE chat_id = :chat_id");
            $stmt->execute([':chat_id' => $chatId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
