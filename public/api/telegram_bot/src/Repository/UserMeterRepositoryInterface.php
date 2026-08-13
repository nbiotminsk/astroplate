<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

interface UserMeterRepositoryInterface
{
    public function getMetersByChatId(string $chatId): array;
    public function addMeter(string $chatId, string $serial, string $name, string $deviceId = ''): void;
    public function removeMeter(string $chatId, string $serial): void;
}
