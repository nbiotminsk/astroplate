<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Telegram;

class MonthArchiveCallback implements CommandInterface
{
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

        Telegram::answerCallbackQuery($cbId, $token);
        $device = MeterService::deviceLookup($config, $serial);
        if ($device) {
            $monthReport = ReportService::buildMonthReport($config, $device);
            Telegram::sendMessage($chatId, $monthReport, $token);
        } else {
            Telegram::sendMessage($chatId, "Устройство не найдено.", $token);
        }
    }
}
