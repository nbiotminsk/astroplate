<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\Repository\UserMeterRepositoryInterface;
use TelegramBot\Telegram;

class DelDeviceCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private UserMeterRepositoryInterface $userMeterRepo
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && str_starts_with($update->text, '/del ');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $serial = trim(substr($update->text, 5));

        $this->userMeterRepo->removeMeter($chatId, $serial);
        $newKey = $this->telegram->buildMainReplyKeyboard($chatId);
        $this->telegram->sendMessage($chatId, "🗑 Счетчик <code>{$serial}</code> удален из списка ваших сохраненных приборов.", $token, $newKey);
    }
}
