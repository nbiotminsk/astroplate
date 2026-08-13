<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\Repository\UserMeterRepositoryInterface;
use TelegramBot\Telegram;

class DelMeterCallback implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private UserMeterRepositoryInterface $userMeterRepo
    ) {}

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

        $this->userMeterRepo->removeMeter($chatId, $serial);
        $this->telegram->answerCallbackQuery($cbId, $token, "Счетчик {$serial} удален!");
        $replyKey = $this->telegram->buildMainReplyKeyboard($chatId);
        $this->telegram->sendMessage($chatId, "🗑 Счетчик <code>{$serial}</code> удален из ваших приборов.", $token, $replyKey);
    }
}
