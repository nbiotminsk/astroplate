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

        $device = $this->meterService->deviceLookup($config, $text);
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
            $isRetry = $update->callbackData === 'is_auto_retry'; // Flag to distinguish retry
            if (!$isRetry) {
                $this->telegram->sendMessage($chatId, "⚠️ <i>Сервер сбора данных временно недоступен.\nБот автоматически попробует запросить данные через 10 секунд и пришлет ответ.</i>", $token);
                
                // Для Webhook (когда работает fastcgi_finish_request) мы можем просто подождать в фоне
                sleep(10);
                
                try {
                    $report = $this->reportService->buildReport($config, $device);
                    $keyboard = $this->telegram->buildDeviceKeyboard($serial, $isAdded);
                    $this->telegram->sendMessage($chatId, $report, $token, $keyboard);
                } catch (\TelegramBot\Exception\ApiUnavailableException $e2) {
                    $this->telegram->sendMessage($chatId, "⚠️ <i>Сервер сбора данных всё ещё недоступен. Попробуйте позже.</i>", $token);
                }
            } else {
                $this->telegram->sendMessage($chatId, "⚠️ <i>Сервер сбора данных всё ещё недоступен. Попробуйте позже.</i>", $token);
            }
        }
    }
}
