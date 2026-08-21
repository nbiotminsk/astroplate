<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use TelegramBot\DTO\DeviceDTO;
use TelegramBot\Storage;

class JsonDeviceRepository implements DeviceRepositoryInterface
{
    public function loadAll(): array
    {
        return Storage::loadRegisteredDevices();
    }

    public function findBySerialOrName(string $input): ?DeviceDTO
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $devices = $this->loadAll();
        if (isset($devices[(int) $input])) {
            return DeviceDTO::fromArray($devices[(int) $input], (string) $input);
        }

        foreach ($devices as $id => $info) {
            if (mb_strtolower($info['name'] ?? '', 'UTF-8') === mb_strtolower($input, 'UTF-8')) {
                return DeviceDTO::fromArray($info, (string) $id);
            }
        }

        return null;
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
        Storage::registerCustomDevice($serial, $uuid, $name, $initialValues, $address, $activeChannels, $channels);
    }
}
