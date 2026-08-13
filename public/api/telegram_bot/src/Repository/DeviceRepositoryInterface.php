<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use TelegramBot\DTO\DeviceDTO;

interface DeviceRepositoryInterface
{
    public function loadAll(): array;
    public function findBySerialOrName(string $input): ?DeviceDTO;
    public function registerDevice(string $serial, string $uuid, string $name, array $initialValues = []): void;
}
