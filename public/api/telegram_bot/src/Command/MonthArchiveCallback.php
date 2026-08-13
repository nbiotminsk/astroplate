<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Telegram;

class MonthArchiveCallback implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private ReportService $reportService
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return $update->isCallbackQuery && str_starts_with($update->callbackData, 'month_');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $cbId = $update->callbackQueryId;
        $chatId = $update->chatId;
        $serial = str_replace('month_', '', $update->callbackData);

        $this->telegram->answerCallbackQuery($cbId, $token);
        $device = $this->meterService->deviceLookup($config, $serial);
        if ($device) {
            $monthReport = $this->reportService->buildMonthReport($config, $device);
            $this->telegram->sendMessage($chatId, $monthReport, $token);
        } else {
            $this->telegram->sendMessage($chatId, "Устройство не найдено.", $token);
        }
    }
}
