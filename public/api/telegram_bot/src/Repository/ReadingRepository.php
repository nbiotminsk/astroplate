<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use PDO;
use TelegramBot\Database;
use TelegramBot\DTO\HistoricalValueDTO;
use TelegramBot\MeterService;
use TelegramBot\Storage;
use Throwable;

class ReadingRepository
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    private function getPdo(): ?PDO
    {
        return Database::getConnection($this->config);
    }

    public function saveHistoricalReadings(string $deviceId, int $channelNumber, array $records): void
    {
        if (empty($records) || empty($deviceId)) {
            return;
        }

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'mysql') {
                $sql = "INSERT INTO meter_readings (device_id, channel_number, reading_date, value, value_raw, value_type)
                        VALUES (:dev_id, :ch_num, :reading_date, :val, :val_raw, :val_type)
                        ON DUPLICATE KEY UPDATE
                            value = VALUES(value),
                            value_raw = VALUES(value_raw),
                            value_type = VALUES(value_type)";
            } else {
                $sql = "INSERT OR REPLACE INTO meter_readings (device_id, channel_number, reading_date, value, value_raw, value_type)
                        VALUES (:dev_id, :ch_num, :reading_date, :val, :val_raw, :val_type)";
            }

            $stmt = $pdo->prepare($sql);
            $pdo->beginTransaction();

            foreach ($records as $rec) {
                $date = null;
                $val = null;
                $valRaw = null;
                $valType = 'DEVICE_DATA';

                if ($rec instanceof HistoricalValueDTO) {
                    $date = $rec->date;
                    $val = $rec->value;
                    $valRaw = $rec->valueRaw;
                    $valType = $rec->valueType ?? 'DEVICE_DATA';
                } elseif (is_array($rec)) {
                    $date = $rec['date'] ?? $rec['last_value_date'] ?? null;
                    $val = isset($rec['value']) ? (float) $rec['value'] : (isset($rec['last_value']) ? (float) $rec['last_value'] : null);
                    $valRaw = isset($rec['value_raw']) ? (float) $rec['value_raw'] : null;
                    $valType = (string) ($rec['value_type'] ?? 'DEVICE_DATA');
                }

                if ($date === null || $val === null) {
                    continue;
                }

                $formattedDate = date('Y-m-d H:i:s', MeterService::parseUtcTimestamp((string) $date));

                $stmt->execute([
                    ':dev_id' => $deviceId,
                    ':ch_num' => $channelNumber,
                    ':reading_date' => $formattedDate,
                    ':val' => (float) $val,
                    ':val_raw' => $valRaw !== null ? (float) $valRaw : null,
                    ':val_type' => $valType,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Storage::log('ReadingRepository::saveHistoricalReadings failed: ' . $e->getMessage());
        }
    }

    /**
     * @return HistoricalValueDTO[]
     */
    public function getHistoricalReadings(string $deviceId, int $channelNumber, ?string $dateFrom = null, ?string $dateTo = null, int $limit = 500): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return [];
        }

        try {
            $params = [
                ':dev_id' => $deviceId,
                ':ch_num' => $channelNumber,
            ];

            $where = "device_id = :dev_id AND channel_number = :ch_num";
            if ($dateFrom !== null) {
                $where .= " AND reading_date >= :date_from";
                $params[':date_from'] = $dateFrom;
            }
            if ($dateTo !== null) {
                $where .= " AND reading_date <= :date_to";
                $params[':date_to'] = $dateTo;
            }

            $sql = "SELECT reading_date, value, value_raw, value_type
                    FROM meter_readings
                    WHERE {$where}
                    ORDER BY reading_date DESC
                    LIMIT {$limit}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $result[] = new HistoricalValueDTO(
                    channelNumber: $channelNumber,
                    date: (string) $row['reading_date'],
                    value: (float) $row['value'],
                    valueRaw: $row['value_raw'] !== null ? (float) $row['value_raw'] : null,
                    valueType: (string) ($row['value_type'] ?? 'DEVICE_DATA')
                );
            }

            return $result;
        } catch (Throwable $e) {
            Storage::log('ReadingRepository::getHistoricalReadings failed: ' . $e->getMessage());
            return [];
        }
    }

    public function saveDeviceInfoSnapshot(string $deviceId, array $payload): void
    {
        if (empty($deviceId) || empty($payload)) {
            return;
        }

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

            if ($driver === 'mysql') {
                $sql = "INSERT INTO device_info_cache (device_id, payload_json)
                        VALUES (:dev_id, :payload)
                        ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = CURRENT_TIMESTAMP";
            } else {
                $sql = "INSERT OR REPLACE INTO device_info_cache (device_id, payload_json) VALUES (:dev_id, :payload)";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':dev_id' => $deviceId,
                ':payload' => $json,
            ]);
        } catch (Throwable $e) {
            Storage::log('ReadingRepository::saveDeviceInfoSnapshot failed: ' . $e->getMessage());
        }
    }

    public function getDeviceInfoSnapshot(string $deviceId): ?array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT payload_json FROM device_info_cache WHERE device_id = :dev_id LIMIT 1");
            $stmt->execute([':dev_id' => $deviceId]);
            $raw = $stmt->fetchColumn();

            if ($raw) {
                $decoded = json_decode((string) $raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return null;
        } catch (Throwable $e) {
            Storage::log('ReadingRepository::getDeviceInfoSnapshot failed: ' . $e->getMessage());
            return null;
        }
    }
}
