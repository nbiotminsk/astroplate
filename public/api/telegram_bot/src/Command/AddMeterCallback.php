<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\Storage;
use TelegramBot\Telegram;

class AddMeterCallback implements CommandInterface
{
    public function supports(TelegramUpdateDTO $update): bool
    {
        return $update->isCallbackQuery && str_starts_with($update->callbackData, 'add_');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $cbId = $update->callbackQueryId;
        $chatId = $update->chatId;
        $serial = str_replace('add_', '', $update->callbackData);

        $device = MeterService::deviceLookup($config, $serial);
        if ($device) {
            Storage::addUserMeter($chatId, $serial, $device->name);
            Telegram::answerCallbackQuery($cbId, $token, "Счетчик {$serial} добавлен!");
            $replyKey = Telegram::buildMainReplyKeyboard($chatId);
            Telegram::sendMessage($chatId, "✅ Счетчик <b>{$device->name}</b> ({$serial}) добавлен в меню «📋 Мои счетчики».", $token, $replyKey);
        } else {
            Telegram::answerCallbackQuery($cbId, $token, "Не удалось найти счетчик.");
        }
    }
}
