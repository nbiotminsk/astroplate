<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use TelegramBot\Storage;

class JsonUserMeterRepository implements UserMeterRepositoryInterface
{
    public function getMetersByChatId(string $chatId): array
    {
        return Storage::getUserMeters($chatId);
    }

    public function addMeter(string $chatId, string $serial, string $name): void
    {
        Storage::addUserMeter($chatId, $serial, $name);
    }

    public function removeMeter(string $chatId, string $serial): void
    {
        Storage::removeUserMeter($chatId, $serial);
    }
}
