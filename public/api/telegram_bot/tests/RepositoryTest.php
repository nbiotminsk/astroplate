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

        // SQL Repositories with in-memory SQLite PDO
        $sqlitePdo = new \PDO('sqlite::memory:');
        $sqlitePdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        \TelegramBot\Database::setConnection($sqlitePdo);
        \TelegramBot\Database::autoMigrate($sqlitePdo);

        $sqlDevRepo = new \TelegramBot\Repository\SqlDeviceRepository();
        $sqlDevRepo->registerDevice('1234567', 'uuid-1234', 'Счетчик Тест', ['1' => 10.5], 'ул. Тестовая 1', [1]);
        $dev = $sqlDevRepo->findBySerialOrName('1234567');
        TestRunner::assert($dev !== null, 'SqlDeviceRepository finds registered device');
        if ($dev) {
            TestRunner::assertEquals('Счетчик Тест', $dev->name, 'SqlDeviceRepository returns correct name');
            TestRunner::assertEquals('ул. Тестовая 1', $dev->address, 'SqlDeviceRepository returns correct address');
        }

        $sqlUserRepo = new \TelegramBot\Repository\SqlUserMeterRepository();
        $sqlUserRepo->addMeter('chat_sql_1', '1234567', 'Мой Счётчик', 'uuid-1234', 'ул. Тестовая 1');
        $userMeters = $sqlUserRepo->getMetersByChatId('chat_sql_1');
        TestRunner::assert(isset($userMeters['1234567']), 'SqlUserMeterRepository gets user meters');

        $sqlUserRepo->setUserState('chat_sql_1', ['step' => 'TEST_STEP']);
        $state = $sqlUserRepo->getUserState('chat_sql_1');
        TestRunner::assertEquals('TEST_STEP', $state['step'] ?? '', 'SqlUserMeterRepository state persistence');

        $sqlUserRepo->clearUserState('chat_sql_1');
        $stateCleared = $sqlUserRepo->getUserState('chat_sql_1');
        TestRunner::assert(empty($stateCleared), 'SqlUserMeterRepository clearUserState');

        $sqlUserRepo->removeMeter('chat_sql_1', '1234567');
        $userMetersAfterRemove = $sqlUserRepo->getMetersByChatId('chat_sql_1');
        TestRunner::assert(!isset($userMetersAfterRemove['1234567']), 'SqlUserMeterRepository removes meter');

        $readingRepo = new \TelegramBot\Repository\ReadingRepository();
        $readingRepo->saveHistoricalReadings('uuid-1234', 1, [
            new \TelegramBot\DTO\HistoricalValueDTO(1, '2026-08-01 00:00:00', 10.5, 10.5, null, null, 'DEVICE_DATA'),
            new \TelegramBot\DTO\HistoricalValueDTO(1, '2026-08-15 00:00:00', 15.8, 15.8, null, null, 'DEVICE_DATA'),
        ]);
        $readings = $readingRepo->getHistoricalReadings('uuid-1234', 1);
        TestRunner::assertEquals(2, count($readings), 'ReadingRepository retrieves historical readings');

        $readingRepo->saveDeviceInfoSnapshot('uuid-1234', ['test' => 'snapshot']);
        $snapshot = $readingRepo->getDeviceInfoSnapshot('uuid-1234');
        TestRunner::assertEquals('snapshot', $snapshot['test'] ?? '', 'ReadingRepository saves and gets device info snapshot');

        \TelegramBot\Database::reset();
    }
}
