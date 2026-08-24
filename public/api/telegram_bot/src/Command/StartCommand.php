<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\Telegram;

class StartCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && ($update->text === '/start' || $update->text === '/help');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $mainKey = $this->telegram->buildMainReplyKeyboard($update->chatId);
        $this->telegram->sendMessage($update->chatId, Telegram::TO_CMD, $token, $mainKey);
    }
}
