<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\ReportService;
use TelegramBot\Telegram;

class MyMetersCommand implements CommandInterface
{
    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && ($update->text === '/my' || $update->text === '📋 Мои счетчики');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $mainKey = Telegram::buildMainReplyKeyboard($update->chatId);
        Telegram::sendMessage($update->chatId, ReportService::userMetersList($config, $update->chatId), $token, $mainKey);
    }
}
