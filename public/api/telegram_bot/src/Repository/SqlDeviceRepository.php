<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use PDO;
use TelegramBot\Database;
use TelegramBot\DTO\DeviceDTO;
use TelegramBot\Storage;
use Throwable;

class SqlDeviceRepository implements DeviceRepositoryInterface
{
    private array $config;
    private JsonDeviceRepository $fallbackJsonRepo;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->fallbackJsonRepo = new JsonDeviceRepository();
    }

    private function getPdo(): ?PDO
    {
        return Database::getConnection($this->config);
    }

    public function loadAll(): array
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return $this->fallbackJsonRepo->loadAll();
        }

        try {
            $stmt = $pdo->query("SELECT * FROM devices");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $devices = [];
            foreach ($rows as $row) {
                $serial = (string) ($row['serial_number'] ?? '');
                if ($serial === '') {
                    continue;
                }

                $activeChannels = !empty($row['active_channels'])
                    ? json_decode((string) $row['active_channels'], true)
                    : [1, 2];

                $channelsConfig = !empty($row['channels_config'])
                    ? json_decode((string) $row['channels_config'], true)
                    : [];

                $initialValues = [];
                foreach ($channelsConfig as $chNum => $conf) {
                    if (isset($conf['user_initial']) && $conf['user_initial'] !== null) {
                        $initialValues[(string) $chNum] = (float) $conf['user_initial'];
                    }
                }

                $devices[$serial] = new DeviceDTO(
                    deviceId: (string) ($row['device_id'] ?? ''),
                    serialNumber: $serial,
                    name: (string) ($row['name'] ?? $serial),
                    initialValues: $initialValues,
                    address: !empty($row['address']) ? (string) $row['address'] : null,
                    activeChannels: is_array($activeChannels) ? $activeChannels : [1, 2],
                    channels: is_array($channelsConfig) ? $channelsConfig : []
                );
            }

            return $devices;
        } catch (Throwable $e) {
            Storage::log('SqlDeviceRepository::loadAll failed, falling back to JSON: ' . $e->getMessage());
            return $this->fallbackJsonRepo->loadAll();
        }
    }

    public function findBySerialOrName(string $input): ?DeviceDTO
    {
        $clean = trim($input);
        if ($clean === '') {
            return null;
        }

        $pdo = $this->getPdo();
        if (!$pdo) {
            return $this->fallbackJsonRepo->findBySerialOrName($input);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM devices WHERE serial_number = :input OR name = :input LIMIT 1");
            $stmt->execute([':input' => $clean]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            $serial = (string) ($row['serial_number'] ?? '');
            $activeChannels = !empty($row['active_channels'])
                ? json_decode((string) $row['active_channels'], true)
                : [1, 2];

            $channelsConfig = !empty($row['channels_config'])
                ? json_decode((string) $row['channels_config'], true)
                : [];

            $initialValues = [];
            foreach ($channelsConfig as $chNum => $conf) {
                if (isset($conf['user_initial']) && $conf['user_initial'] !== null) {
                    $initialValues[(string) $chNum] = (float) $conf['user_initial'];
                }
            }

            return new DeviceDTO(
                deviceId: (string) ($row['device_id'] ?? ''),
                serialNumber: $serial,
                name: (string) ($row['name'] ?? $serial),
                initialValues: $initialValues,
                address: !empty($row['address']) ? (string) $row['address'] : null,
                activeChannels: is_array($activeChannels) ? $activeChannels : [1, 2],
                channels: is_array($channelsConfig) ? $channelsConfig : []
            );
        } catch (Throwable $e) {
            Storage::log('SqlDeviceRepository::findBySerialOrName failed, falling back to JSON: ' . $e->getMessage());
            return $this->fallbackJsonRepo->findBySerialOrName($input);
        }
    }

    public function registerDevice(
        string $serial,
        string $uuid,
        string $name,
        array $initialValues = [],
        ?string $address = null,
        ?array $activeChannels = null,
        ?array $channels = null
    ): void {
        // Всегда сохраняем в JSON как резервную копию
        $this->fallbackJsonRepo->registerDevice($serial, $uuid, $name, $initialValues, $address, $activeChannels, $channels);

        $pdo = $this->getPdo();
        if (!$pdo) {
            return;
        }

        try {
            $channelsConfig = $channels ?? [];
            if (!empty($initialValues)) {
                foreach ($initialValues as $chNum => $initVal) {
                    $chNumStr = (string) $chNum;
                    $channelsConfig[$chNumStr]['user_initial'] = (float) $initVal;
                    if (!isset($channelsConfig[$chNumStr]['base_api_value'])) {
                        $channelsConfig[$chNumStr]['base_api_value'] = null;
                    }
                }
            }

            $activeJson = json_encode($activeChannels ?? [1, 2]);
            $channelsJson = json_encode($channelsConfig, JSON_UNESCAPED_UNICODE);

            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $sql = "INSERT INTO devices (serial_number, device_id, name, address, active_channels, channels_config)
                        VALUES (:serial, :uuid, :name, :address, :active_ch, :channels_cfg)
                        ON DUPLICATE KEY UPDATE
                            device_id = VALUES(device_id),
                            name = VALUES(name),
                            address = VALUES(address),
                            active_channels = VALUES(active_channels),
                            channels_config = VALUES(channels_config),
                            updated_at = CURRENT_TIMESTAMP";
            } else {
                $sql = "INSERT OR REPLACE INTO devices (serial_number, device_id, name, address, active_channels, channels_config)
                        VALUES (:serial, :uuid, :name, :address, :active_ch, :channels_cfg)";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':serial' => $serial,
                ':uuid' => $uuid,
                ':name' => $name,
                ':address' => $address,
                ':active_ch' => $activeJson,
                ':channels_cfg' => $channelsJson,
            ]);
        } catch (Throwable $e) {
            Storage::log('SqlDeviceRepository::registerDevice failed: ' . $e->getMessage());
        }
    }
}
