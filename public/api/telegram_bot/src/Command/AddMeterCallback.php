<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\Repository\UserMeterRepositoryInterface;
use TelegramBot\Telegram;

class AddMeterCallback implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private UserMeterRepositoryInterface $userMeterRepo
    ) {}

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

        $device = $this->meterService->deviceLookup($config, $serial);
        if ($device) {
            $this->userMeterRepo->addMeter($chatId, $serial, $device->name, $device->deviceId);
            $this->telegram->answerCallbackQuery($cbId, $token, "Счетчик {$serial} добавлен!");
            $replyKey = $this->telegram->buildMainReplyKeyboard($chatId);
            $this->telegram->sendMessage($chatId, "✅ Счетчик <b>{$device->name}</b> ({$serial}) добавлен в меню «📋 Мои счетчики».", $token, $replyKey);
        } else {
            $this->telegram->answerCallbackQuery($cbId, $token, "Не удалось найти счетчик.");
        }
    }
}
