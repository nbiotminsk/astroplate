<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\ReportService;
use TelegramBot\Telegram;

class MyMetersCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private ReportService $reportService
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && ($update->text === '/my' || $update->text === '📋 Мои счетчики');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $mainKey = $this->telegram->buildMainReplyKeyboard($update->chatId);
        $text = $this->reportService->userMetersList($config, $update->chatId);

        $this->telegram->sendMessage($update->chatId, $text, $token, $mainKey);
    }
}
