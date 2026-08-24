<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

use TelegramBot\Command\CommandDispatcher;
use TelegramBot\Container;
use TelegramBot\Repository\DeviceRepositoryInterface;
use TelegramBot\Repository\MeterCacheRepositoryInterface;
use TelegramBot\Repository\UserMeterRepositoryInterface;

class ContainerTest
{
    public static function run(): void
    {
        echo "\n🧪 4. Тестирование Внедрения Зависимостей (DI Container)...\n";

        $config = ['telegram_token' => 'test_token'];
        $container = new Container($config);

        $deviceRepo = $container->get(DeviceRepositoryInterface::class);
        TestRunner::assert($deviceRepo instanceof DeviceRepositoryInterface, 'Container resolves DeviceRepositoryInterface');

        $userRepo = $container->get(UserMeterRepositoryInterface::class);
        TestRunner::assert($userRepo instanceof UserMeterRepositoryInterface, 'Container resolves UserMeterRepositoryInterface');

        $cacheRepo = $container->get(MeterCacheRepositoryInterface::class);
        TestRunner::assert($cacheRepo instanceof MeterCacheRepositoryInterface, 'Container resolves MeterCacheRepositoryInterface');

        $dispatcher = $container->get(CommandDispatcher::class);
        TestRunner::assert($dispatcher instanceof CommandDispatcher, 'Container resolves CommandDispatcher');

        // Singleton resolution verification
        $deviceRepo2 = $container->get(DeviceRepositoryInterface::class);
        TestRunner::assert($deviceRepo === $deviceRepo2, 'Container returns shared singleton instance');
    }
}
