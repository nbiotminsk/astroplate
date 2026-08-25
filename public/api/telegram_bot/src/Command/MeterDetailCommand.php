<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Repository\UserMeterRepositoryInterface;
use TelegramBot\Telegram;

class MeterDetailCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private ReportService $reportService,
        private UserMeterRepositoryInterface $userMeterRepo
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && $update->text !== '';
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $text = $update->text;
        $mainKey = $this->telegram->buildMainReplyKeyboard($chatId);

        if (preg_match('/\((\d+)\)$/', $text, $matches)) {
            $text = $matches[1];
        }

        $device = $this->meterService->deviceLookup($config, $text, $chatId);
        if (!$device) {
            $this->telegram->sendMessage($chatId, "Устройство не найдено.\n\n" . Telegram::TO_CMD, $token, $mainKey);
            return;
        }

        $serial = $device->serialNumber !== '' ? $device->serialNumber : $text;
        $userMeters = $this->userMeterRepo->getMetersByChatId($chatId);
        $isAdded = isset($userMeters[$serial]);

        try {
            $report = $this->reportService->buildReport($config, $device);
            $keyboard = $this->telegram->buildDeviceKeyboard($serial, $isAdded);
            $this->telegram->sendMessage($chatId, $report, $token, $keyboard);
        } catch (\TelegramBot\Exception\ApiUnavailableException $e) {
            $retryKeyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 Попробовать снова', 'callback_data' => "back_dev_{$serial}"]],
                ]
            ];
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ <b>Сервер сбора данных временно недоступен.</b>\nПожалуйста, нажмите кнопку ниже для повтора:",
                $token,
                $retryKeyboard
            );
        }
    }
}
