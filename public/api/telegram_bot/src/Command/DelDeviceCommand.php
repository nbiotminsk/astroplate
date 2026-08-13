<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\Storage;
use TelegramBot\Telegram;

class DelDeviceCommand implements CommandInterface
{
    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && str_starts_with($update->text, '/del ');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $serial = trim(substr($update->text, 5));

        Storage::removeUserMeter($chatId, $serial);
        $newKey = Telegram::buildMainReplyKeyboard($chatId);
        Telegram::sendMessage($chatId, "🗑 Счетчик <code>{$serial}</code> удален из списка ваших сохраненных приборов.", $token, $newKey);
    }
}
