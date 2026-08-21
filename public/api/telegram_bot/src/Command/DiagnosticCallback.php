<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Telegram;

class DiagnosticCallback implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private ReportService $reportService
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return $update->isCallbackQuery && str_starts_with($update->callbackData, 'diag_');
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $cbId = $update->callbackQueryId;
        $chatId = $update->chatId;
        $serial = str_replace('diag_', '', $update->callbackData);

        Telegram::answerCallbackQuery($cbId, $token);
        $device = $this->meterService->deviceLookup($config, $serial);
        if ($device) {
            $diagReport = $this->reportService->buildDiagnosticReport($config, $device);
            $key = Telegram::buildDiagnosticKeyboard($serial);
            Telegram::sendMessage($chatId, $diagReport, $token, $key);
        } else {
            Telegram::sendMessage($chatId, "Прибор не найден.", $token);
        }
    }
}
