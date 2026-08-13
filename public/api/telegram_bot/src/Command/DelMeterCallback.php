<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\Storage;
use TelegramBot\Telegram;

class DelMeterCallback implements CommandInterface
{
    public function supports(TelegramUpdateDTO $update): bool
    {
        return $update->isCallbackQuery && str_starts_with($update->callbackData, 'del_');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $cbId = $update->callbackQueryId;
        $chatId = $update->chatId;
        $serial = str_replace('del_', '', $update->callbackData);

        Storage::removeUserMeter($chatId, $serial);
        Telegram::answerCallbackQuery($cbId, $token, "Счетчик {$serial} удален!");
        $replyKey = Telegram::buildMainReplyKeyboard($chatId);
        Telegram::sendMessage($chatId, "🗑 Счетчик <code>{$serial}</code> удален из ваших приборов.", $token, $replyKey);
    }
}
