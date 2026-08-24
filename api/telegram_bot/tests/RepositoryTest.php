<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

use TelegramBot\Repository\JsonDeviceRepository;
use TelegramBot\Repository\JsonUserMeterRepository;
use TelegramBot\Repository\JsonMeterCacheRepository;

class RepositoryTest
{
    public static function run(): void
    {
        echo "\n🧪 2. Тестирование Репозиториев (Storage Layer)...\n";

        // JsonDeviceRepository
        $deviceRepo = new JsonDeviceRepository();
        $allDevices = $deviceRepo->loadAll();
        TestRunner::assert(is_array($allDevices), 'JsonDeviceRepository loadAll returns array');

        $foundDev = $deviceRepo->findBySerialOrName('8527038');
        TestRunner::assert($foundDev !== null, 'JsonDeviceRepository findBySerial 8527038');
        if ($foundDev) {
            TestRunner::assertEquals('Fluo', $foundDev->name, 'JsonDeviceRepository device name');
        }

        // JsonUserMeterRepository
        $userRepo = new JsonUserMeterRepository();
        $testChatId = 'test_chat_999999';
        $userRepo->addMeter($testChatId, '8527038', 'Fluo', '2e50bc92-6c87-4b64-b22e-e96e7997476f');

        $meters = $userRepo->getMetersByChatId($testChatId);
        $meterEntry = $meters['8527038'] ?? null;
        $meterName = is_array($meterEntry) ? ($meterEntry['name'] ?? null) : $meterEntry;
        $meterUuid = is_array($meterEntry) ? ($meterEntry['device_id'] ?? null) : null;
        TestRunner::assertEquals('Fluo', $meterName, 'JsonUserMeterRepository add & get meter name');
        TestRunner::assertEquals('2e50bc92-6c87-4b64-b22e-e96e7997476f', $meterUuid, 'JsonUserMeterRepository add & get meter device_id (UUID)');

        $userRepo->removeMeter($testChatId, '8527038');
        $metersAfterRemove = $userRepo->getMetersByChatId($testChatId);
        TestRunner::assert(!isset($metersAfterRemove['8527038']), 'JsonUserMeterRepository remove meter');

        // JsonMeterCacheRepository
        $cacheRepo = new JsonMeterCacheRepository();
        $cacheData = $cacheRepo->loadCache();
        TestRunner::assert(is_array($cacheData), 'JsonMeterCacheRepository loadCache returns array');
    }
}
