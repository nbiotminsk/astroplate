<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Telegram;

class DiagnosticCallback implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private ReportService $reportService
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return $update->isCallbackQuery && str_starts_with($update->callbackData, 'diag_');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $cbId = $update->callbackQueryId;
        $chatId = $update->chatId;
        $cbData = $update->callbackData;

        Telegram::answerCallbackQuery($cbId, $token);

        if (str_starts_with($cbData, 'diag_ch_')) {
            $serial = str_replace('diag_ch_', '', $cbData);
            $sub = 'ch';
        } elseif (str_starts_with($cbData, 'diag_bat_')) {
            $serial = str_replace('diag_bat_', '', $cbData);
            $sub = 'bat';
        } elseif (str_starts_with($cbData, 'diag_temp_')) {
            $serial = str_replace('diag_temp_', '', $cbData);
            $sub = 'temp';
        } elseif (str_starts_with($cbData, 'diag_clock_')) {
            $serial = str_replace('diag_clock_', '', $cbData);
            $sub = 'clock';
        } else {
            $serial = str_replace('diag_', '', $cbData);
            $sub = 'menu';
        }

        $device = $this->meterService->deviceLookup($config, $serial);
        if (!$device) {
            Telegram::sendMessage($chatId, "Прибор не найден.", $token);
            return;
        }

        switch ($sub) {
            case 'ch':
                $report = $this->reportService->buildDiagChannelsReport($config, $device);
                $key = Telegram::buildDiagSubKeyboard($serial);
                break;
            case 'bat':
                $report = $this->reportService->buildDiagBatteryReport($config, $device);
                $key = Telegram::buildDiagSubKeyboard($serial);
                break;
            case 'temp':
                $report = $this->reportService->buildDiagTemperatureReport($config, $device);
                $key = Telegram::buildDiagSubKeyboard($serial);
                break;
            case 'clock':
                $report = $this->reportService->buildDiagClockReport($config, $device);
                $key = Telegram::buildDiagSubKeyboard($serial);
                break;
            default:
                $report = $this->reportService->buildDiagnosticReport($config, $device);
                $key = Telegram::buildDiagnosticKeyboard($serial);
                break;
        }

        Telegram::sendMessage($chatId, $report, $token, $key);
    }
}
