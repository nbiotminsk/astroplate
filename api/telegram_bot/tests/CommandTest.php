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

        $deviceRepo = new JsonDeviceRepository();
        $userMeterRepo = new JsonUserMeterRepository();
        $cacheRepo = new JsonMeterCacheRepository();

        $telegram = new Telegram($userMeterRepo);
        $meterService = new MeterService($deviceRepo, $cacheRepo);
        $reportService = new ReportService($userMeterRepo, $meterService);

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

        // AddDeviceCommand Wizard & FSM tests
        $addBtnUpdate = new TelegramUpdateDTO(5, '777', '➕ Добавить счетчик');
        TestRunner::assert($addCmd->supports($addBtnUpdate), 'AddDeviceCommand supports button ➕ Добавить счетчик');

        $addCmdUpdate = new TelegramUpdateDTO(6, '777', '/add');
        TestRunner::assert($addCmd->supports($addCmdUpdate), 'AddDeviceCommand supports /add');

        // Wizard callback queries support
        $wizCbUpdate = new TelegramUpdateDTO(7, '777', '', true, 'wiz_ch_2', 'cb_wiz');
        TestRunner::assert($addCmd->supports($wizCbUpdate), 'AddDeviceCommand supports callback wiz_ch_2');

        $wizCancelUpdate = new TelegramUpdateDTO(8, '777', '', true, 'wiz_cancel', 'cb_cancel');
        TestRunner::assert($addCmd->supports($wizCancelUpdate), 'AddDeviceCommand supports callback wiz_cancel');

        // FSM State storage check
        \TelegramBot\Storage::setUserState('777', [
            'step' => 'WAITING_ADDRESS',
            'serial' => '8554760',
            'uuid' => 'ae0bf621-39e3-47e5-9126-52ec6e90d242',
            'ch_count' => 2,
        ]);
        $state = \TelegramBot\Storage::getUserState('777');
        TestRunner::assertEquals('WAITING_ADDRESS', $state['step'] ?? '', 'Storage::getUserState returns correct step');
        TestRunner::assertEquals('8554760', $state['serial'] ?? '', 'Storage::getUserState returns serial');

        // While in state, AddDeviceCommand intercepts text messages
        $addrInputUpdate = new TelegramUpdateDTO(9, '777', 'ул. Кольцова 8 корпус 2 кв. 74');
        TestRunner::assert($addCmd->supports($addrInputUpdate), 'AddDeviceCommand intercepts message while user is in active wizard state');

        // Cancel resets state
        $cancelUpdate = new TelegramUpdateDTO(10, '777', '❌ Отмена');
        TestRunner::assert($addCmd->supports($cancelUpdate), 'AddDeviceCommand supports ❌ Отмена');

        \TelegramBot\Storage::clearUserState('777');
        TestRunner::assert(\TelegramBot\Storage::getUserState('777') === null, 'Storage::clearUserState resets user state');

        // Fluo device wizard state test: address step finishes immediately without asking for readings
        \TelegramBot\Storage::setUserState('777', [
            'step' => 'WAITING_ADDRESS',
            'serial' => '9998887',
            'uuid' => '2e50bc92-6c87-4b64-b22e-e96e7997476f',
            'ch_count' => 1,
            'is_fluo' => true,
            'active_channels' => [1],
        ]);
        $fluoAddrUpdate = new TelegramUpdateDTO(10, '777', 'ул. Тестовая 15');
        $addCmd->handle($fluoAddrUpdate, $config);
        TestRunner::assert(\TelegramBot\Storage::getUserState('777') === null, 'Fluo wizard finishes immediately upon address entry');
        $savedFluo = $userMeterRepo->getMetersByChatId('777');
        TestRunner::assert(isset($savedFluo['9998887']), 'Fluo meter is registered for user after address entry');
        $userMeterRepo->removeMeter('777', '9998887');

        // EditDeviceCommand tests
        $editCmd = new \TelegramBot\Command\EditDeviceCommand($telegram, $meterService, $reportService, $deviceRepo, $userMeterRepo);
        
        $editOpenUpdate = new TelegramUpdateDTO(11, '777', '', true, 'edit_8554760', 'cb_edit');
        TestRunner::assert($editCmd->supports($editOpenUpdate), 'EditDeviceCommand supports edit_8554760');

        $editAddrUpdate = new TelegramUpdateDTO(12, '777', '', true, 'edit_addr_8554760', 'cb_edit_addr');
        TestRunner::assert($editCmd->supports($editAddrUpdate), 'EditDeviceCommand supports edit_addr_8554760');

        $editMetersUpdate = new TelegramUpdateDTO(13, '777', '', true, 'edit_meters_8554760', 'cb_edit_meters');
        TestRunner::assert($editCmd->supports($editMetersUpdate), 'EditDeviceCommand supports edit_meters_8554760');

        $editInitUpdate = new TelegramUpdateDTO(14, '777', '', true, 'edit_init_8554760', 'cb_edit_init');
        TestRunner::assert($editCmd->supports($editInitUpdate), 'EditDeviceCommand supports edit_init_8554760');

        $editChUpdate = new TelegramUpdateDTO(15, '777', '', true, 'edit_ch_8554760', 'cb_edit_ch');
        TestRunner::assert($editCmd->supports($editChUpdate), 'EditDeviceCommand supports edit_ch_8554760');

        $setChUpdate = new TelegramUpdateDTO(16, '777', '', true, 'set_ch_8554760_2', 'cb_set_ch');
        TestRunner::assert($editCmd->supports($setChUpdate), 'EditDeviceCommand supports set_ch_8554760_2');

        $backDevUpdate = new TelegramUpdateDTO(17, '777', '', true, 'back_dev_8554760', 'cb_back');
        TestRunner::assert($editCmd->supports($backDevUpdate), 'EditDeviceCommand supports back_dev_8554760');

        // Edit text state support
        \TelegramBot\Storage::setUserState('777', [
            'step' => 'EDIT_ADDRESS',
            'serial' => '8554760',
        ]);
        $editTextInput = new TelegramUpdateDTO(18, '777', 'ул. Новая 15 кв. 42');
        TestRunner::assert($editCmd->supports($editTextInput), 'EditDeviceCommand intercepts text in EDIT_ADDRESS state');

        \TelegramBot\Storage::clearUserState('777');

        // DiagnosticCallback tests
        $diagCb = new \TelegramBot\Command\DiagnosticCallback($telegram, $meterService, $reportService);
        $diagUpdate = new TelegramUpdateDTO(19, '777', '', true, 'diag_8554760', 'cb_diag');
        TestRunner::assert($diagCb->supports($diagUpdate), 'DiagnosticCallback supports diag_8554760');
        TestRunner::assert(!$diagCb->supports($editOpenUpdate), 'DiagnosticCallback rejects other callbacks');

        // PingServerCommand tests
        $pingCmd = new \TelegramBot\Command\PingServerCommand($telegram);
        $pingBtnUpdate = new TelegramUpdateDTO(20, '777', '⚡ Тест сервера');
        $pingCmdUpdate = new TelegramUpdateDTO(21, '777', '/ping');
        $pingCbUpdate = new TelegramUpdateDTO(22, '777', '', true, 'server_ping', 'cb_ping');
        $otherUpdate = new TelegramUpdateDTO(23, '777', 'Счетчик 123');

        TestRunner::assert($pingCmd->supports($pingBtnUpdate), 'PingServerCommand supports button ⚡ Тест сервера');
        TestRunner::assert($pingCmd->supports($pingCmdUpdate), 'PingServerCommand supports command /ping');
        TestRunner::assert($pingCmd->supports($pingCbUpdate), 'PingServerCommand supports callback server_ping');
        TestRunner::assert(!$pingCmd->supports($otherUpdate), 'PingServerCommand rejects unrelated text');
    }
}
