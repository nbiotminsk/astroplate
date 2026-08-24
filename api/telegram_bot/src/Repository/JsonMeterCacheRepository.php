<?php

declare(strict_types=1);

namespace TelegramBot\Repository;

use TelegramBot\Storage;

class JsonMeterCacheRepository implements MeterCacheRepositoryInterface
{
    public function loadCache(): array
    {
        return Storage::loadMeterCache();
    }

    public function saveCache(array $data): void
    {
        Storage::saveMeterCache($data);
    }

    public function clearChannelCache(string $deviceId, int $chNum): void
    {
        $cache = $this->loadCache();
        if ($deviceId !== '' && isset($cache[$deviceId]['channels'][$chNum])) {
            unset($cache[$deviceId]['channels'][$chNum]);
            $this->saveCache($cache);
        }
    }
}
