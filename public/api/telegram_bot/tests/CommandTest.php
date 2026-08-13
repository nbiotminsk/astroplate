<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

use TelegramBot\Command\AddDeviceCommand;
use TelegramBot\Command\AddMeterCallback;
use TelegramBot\Command\CommandDispatcher;
use TelegramBot\Command\DelDeviceCommand;
use TelegramBot\Command\DelMeterCallback;
use TelegramBot\Command\InitMeterCommand;
use TelegramBot\Command\MeterDetailCommand;
use TelegramBot\Command\MonthArchiveCallback;
use TelegramBot\Command\MyMetersCommand;
use TelegramBot\Command\StartCommand;
use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Repository\JsonDeviceRepository;
use TelegramBot\Repository\JsonMeterCacheRepository;
use TelegramBot\Repository\JsonUserMeterRepository;
use TelegramBot\Telegram;

class CommandTest
{
    public static function run(): void
    {
        echo "\n🧪 3. Тестирование Паттерна Команда (Command Pattern & DI)...\n";

        $telegram = new Telegram();
        $meterService = new MeterService();
        $reportService = new ReportService();
        $deviceRepo = new JsonDeviceRepository();
        $userMeterRepo = new JsonUserMeterRepository();
        $cacheRepo = new JsonMeterCacheRepository();

        $startCmd = new StartCommand($telegram);
        $myCmd = new MyMetersCommand($telegram, $reportService);
        $addCmd = new AddDeviceCommand($telegram, $meterService, $deviceRepo, $userMeterRepo);
        $delCmd = new DelDeviceCommand($telegram, $userMeterRepo);
        $initCmd = new InitMeterCommand($telegram, $meterService, $deviceRepo, $cacheRepo);
        $monthCb = new MonthArchiveCallback($telegram, $meterService, $reportService);
        $addCb = new AddMeterCallback($telegram, $meterService, $userMeterRepo);
        $delCb = new DelMeterCallback($telegram, $userMeterRepo);
        $detailCmd = new MeterDetailCommand($telegram, $meterService, $reportService, $userMeterRepo);

        $dispatcher = new CommandDispatcher([
            $monthCb,
            $addCb,
            $delCb,
            $startCmd,
            $myCmd,
            $addCmd,
            $delCmd,
            $initCmd,
            $detailCmd,
        ]);

        // Start command support check
        $startUpdate = new TelegramUpdateDTO(1, '123', '/start');
        TestRunner::assert($startCmd->supports($startUpdate), 'StartCommand supports /start');
        TestRunner::assert(!$startCmd->supports(new TelegramUpdateDTO(2, '123', '/my')), 'StartCommand rejects /my');

        // MyMeters command support check
        $myUpdate = new TelegramUpdateDTO(3, '123', '/my');
        TestRunner::assert($myCmd->supports($myUpdate), 'MyMetersCommand supports /my');

        // Callback query support check
        $cbMonthUpdate = new TelegramUpdateDTO(4, '123', '', true, 'month_8554760', 'cb_1');
        TestRunner::assert($monthCb->supports($cbMonthUpdate), 'MonthArchiveCallback supports month_8554760');
        TestRunner::assert(!$startCmd->supports($cbMonthUpdate), 'StartCommand rejects callback update');

        $config = require __DIR__ . '/../config.php';

        // Dispatcher matching check
        $handled = $dispatcher->dispatch($startUpdate, $config);
        TestRunner::assert($handled, 'CommandDispatcher successfully matched /start');

        $handledCb = $dispatcher->dispatch($cbMonthUpdate, $config);
        TestRunner::assert($handledCb, 'CommandDispatcher successfully matched callback month_');
    }
}
