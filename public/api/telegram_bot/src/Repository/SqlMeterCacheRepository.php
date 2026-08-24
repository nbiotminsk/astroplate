<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use PDO;
use TelegramBot\Database;
use TelegramBot\Storage;
use Throwable;

class SqlMeterCacheRepository implements MeterCacheRepositoryInterface
{
    private array $config;
    private JsonMeterCacheRepository $fallbackJsonRepo;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->fallbackJsonRepo = new JsonMeterCacheRepository();
    }

    private function getPdo(): ?PDO
    {
        return Database::getConnection($this->config);
    }

    public function loadCache(): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return $this->fallbackJsonRepo->loadCache();
        }

        try {
            $stmt = $pdo->query("SELECT cache_key, data_json FROM meter_cache");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $key = (string) $row['cache_key'];
                $decoded = json_decode((string) $row['data_json'], true);
                if (is_array($decoded)) {
                    $result[$key] = $decoded;
                }
            }

            return $result;
        } catch (Throwable $e) {
            Storage::log('SqlMeterCacheRepository::loadCache failed: ' . $e->getMessage());
            return $this->fallbackJsonRepo->loadCache();
        }
    }

    public function saveCache(array $data): void
    {
        $this->fallbackJsonRepo->saveCache($data);

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $pdo->beginTransaction();

            if ($driver === 'mysql') {
                $stmt = $pdo->prepare("INSERT INTO meter_cache (cache_key, data_json)
                                       VALUES (:key, :data)
                                       ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = CURRENT_TIMESTAMP");
            } else {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO meter_cache (cache_key, data_json) VALUES (:key, :data)");
            }

            foreach ($data as $key => $val) {
                $stmt->execute([
                    ':key' => (string) $key,
                    ':data' => json_encode($val, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Storage::log('SqlMeterCacheRepository::saveCache failed: ' . $e->getMessage());
        }
    }

    public function clearChannelCache(string $deviceId, int $chNum): void
    {
        $this->fallbackJsonRepo->clearChannelCache($deviceId, $chNum);

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $prefix = "{$deviceId}_{$chNum}_%";
            $stmt = $pdo->prepare("DELETE FROM meter_cache WHERE cache_key LIKE :prefix OR cache_key = :exact");
            $stmt->execute([
                ':prefix' => $prefix,
                ':exact' => "{$deviceId}_{$chNum}",
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
