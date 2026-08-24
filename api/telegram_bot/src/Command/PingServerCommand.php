<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\Storage;
use TelegramBot\Telegram;
use TelegramBot\UnicBoard;

class PingServerCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        if ($update->isCallbackQuery) {
            return $update->callbackData === 'server_ping';
        }

        $text = mb_strtolower($update->text, 'UTF-8');
        return $text === '⚡ тест сервера' ||
               $text === 'тест сервера' ||
               $text === '/ping' ||
               $text === '/test';
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $cbId = $update->callbackQueryId;

        if ($update->isCallbackQuery && $cbId !== '') {
            Telegram::answerCallbackQuery($cbId, $token, 'Выполняю проверку...');
        }

        $startTs = microtime(true);
        $unicResp = UnicBoard::getAllDevices($config, 1);
        $durationMs = round((microtime(true) - $startTs) * 1000, 1);

        $httpCode = $unicResp['http_status'] ?? 0;
        $isOk = ($unicResp['ok'] ?? false) || ($httpCode >= 200 && $httpCode < 300);

        if ($isOk) {
            $apiStatus = "✅ <b>Доступен (HTTP {$httpCode})</b>";
        } elseif ($httpCode === 0) {
            $apiStatus = "❌ <b>Нет связи (Timeout / Ошибка сети)</b>";
        } else {
            $apiStatus = "⚠️ <b>Ошибка ответа (HTTP {$httpCode})</b>";
        }

        $nowDate = date('d.m.Y H:i:s');
        $tz = date_default_timezone_get();
        $devicesCount = count(Storage::loadRegisteredDevices());

        $errDetail = '';
        if (!$isOk && !empty($unicResp['errors'])) {
            $errItem = $unicResp['errors'][0] ?? [];
            $errText = is_array($errItem) ? ($errItem['error_message'] ?? json_encode($errItem)) : (string) $errItem;
            if ($errText !== '') {
                // Маскируем внутренние адреса и хосты
                $cleanErr = (string) preg_replace('/https?:\/\/[^\/\s]+/i', 'сервер', $errText);
                $cleanErr = (string) preg_replace('/[a-z0-9.-]+\.unicboard\.by/i', 'сервер', $cleanErr);
                $errDetail = "• Ошибка: <code>" . htmlspecialchars($cleanErr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code>\n";
            }
        }

        $msg = "⚡ <b>Диагностика связи и серверов</b>\n\n";
        $msg .= "🌐 <b>Сервер сбора данных:</b>\n";
        $msg .= "• Статус: {$apiStatus}\n";
        $msg .= "• Время отклика: <b>{$durationMs} мс</b>\n";
        if ($errDetail !== '') {
            $msg .= $errDetail;
        }
        $msg .= "\n";

        $msg .= "🤖 <b>Сервер Telegram-бота:</b>\n";
        $msg .= "• Статус: ✅ <b>Онлайн</b>\n";
        $msg .= "• Время сервера: <b>{$nowDate}</b> ({$tz})\n";
        $msg .= "• Приборов в системе: <b>{$devicesCount}</b>\n\n";

        if ($isOk) {
            $msg .= "<i>Все системы работают в штатном режиме.</i>";
        } else {
            $msg .= "<i>⚠️ Зафиксирована задержка или сбой связи с внешним шлюзом.</i>";
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔄 Повторить тест', 'callback_data' => 'server_ping']],
            ]
        ];

        Telegram::sendMessage($chatId, $msg, $token, $keyboard);
    }
}
