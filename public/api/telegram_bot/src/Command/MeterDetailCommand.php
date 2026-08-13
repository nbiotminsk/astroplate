<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Storage;
use TelegramBot\Telegram;

class MeterDetailCommand implements CommandInterface
{
    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && $update->text !== '';
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $text = $update->text;
        $mainKey = Telegram::buildMainReplyKeyboard($chatId);

        if (preg_match('/\((\d+)\)$/', $text, $matches)) {
            $text = $matches[1];
        }

        $device = MeterService::deviceLookup($config, $text);
        if (!$device) {
            Telegram::sendMessage($chatId, "Устройство не найдено.\n\n" . Telegram::TO_CMD, $token, $mainKey);
            return;
        }

        $serial = $device->serialNumber !== '' ? $device->serialNumber : $text;
        $userMeters = Storage::getUserMeters($chatId);
        $isAdded = isset($userMeters[$serial]);

        $report = ReportService::buildReport($config, $device);
        $keyboard = Telegram::buildDeviceKeyboard($serial, $isAdded);

        Telegram::sendMessage($chatId, $report, $token, $keyboard);
    }
}
