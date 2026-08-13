<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\Repository\DeviceRepositoryInterface;
use TelegramBot\Repository\UserMeterRepositoryInterface;
use TelegramBot\Telegram;

class AddDeviceCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private DeviceRepositoryInterface $deviceRepo,
        private UserMeterRepositoryInterface $userMeterRepo
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && ($update->text === '➕ Добавить счетчик' || str_starts_with($update->text, '/add '));
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $text = $update->text;
        $mainKey = $this->telegram->buildMainReplyKeyboard($chatId);

        if ($text === '➕ Добавить счетчик') {
            $this->telegram->sendMessage($chatId, "Чтобы зарегистрировать новый прибор в боте, отправьте команду:\n<code>/add ID UUID</code>\n\nПример:\n<code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f</code>", $token, $mainKey);
            return;
        }

        $parts = preg_split('/\s+/', trim($text));
        if (count($parts) >= 3) {
            $serial = $parts[1];
            $uuid = $parts[2];
            $name = count($parts) >= 4 ? implode(' ', array_slice($parts, 3)) : "Счетчик {$serial}";

            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                $this->telegram->sendMessage($chatId, "❌ Неверный формат UUID. Укажите корректный UUID прибора.", $token, $mainKey);
                return;
            }

            $this->deviceRepo->registerDevice($serial, $uuid, $name);
            $this->meterService->fetchAndSaveInitialValues($config, $serial, $uuid);
            $this->userMeterRepo->addMeter($chatId, $serial, $name, $uuid);

            $newKey = $this->telegram->buildMainReplyKeyboard($chatId);
            $this->telegram->sendMessage($chatId, "🎉 Новый прибор успешно зарегистрирован!\n\n• <b>ID / Серийный №</b>: <code>{$serial}</code>\n• <b>UUID</b>: <code>{$uuid}</code>\n\nОн также автоматически сохранен в ваше меню «📋 Мои счетчики».", $token, $newKey);
        } else {
            $this->telegram->sendMessage($chatId, "Неверный формат команды.\n\nИспользование:\n<code>/add ID UUID</code>\n\nПример:\n<code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f</code>", $token, $mainKey);
        }
    }
}
