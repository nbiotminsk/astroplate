<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

interface MeterCacheRepositoryInterface
{
    public function loadCache(): array;
    public function saveCache(array $data): void;
    public function clearChannelCache(string $deviceId, int $chNum): void;
}
