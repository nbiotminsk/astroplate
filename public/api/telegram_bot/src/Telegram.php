<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\Repository\UserMeterRepositoryInterface;

class Telegram
{
    public function __construct(
        private ?UserMeterRepositoryInterface $userMeterRepo = null,
        private ?KeyboardBuilder $keyboardBuilder = null
    ) {
        if ($this->keyboardBuilder === null) {
            $this->keyboardBuilder = new KeyboardBuilder($this->userMeterRepo);
        }
    }

    public const TO_CMD = <<<TXT
👋 <b>Добро пожаловать в сервис дистанционного учёта воды!</b>

🔹 <b>Как добавить счётчик:</b>
Нажмите кнопку <b>«➕ Добавить счетчик»</b> внизу или отправьте 7-значный номер модема.

🔹 <b>Управление и показания:</b>
• Нажимайте на кнопки с адресами внизу для просмотра показаний.
• <b>«📅 Архив за месяц»</b> — расход за текущий календарный месяц.
• <b>«⚙️ Диагностика»</b> — вес импульса, число импульсов, часы, батарея, температура.
• <b>«✏️ Изменить»</b> — смена адреса, начальных показаний, номеров счётчиков и входов.

<b>Команды:</b>
/start — открыть главное меню
/my — список ваших адресов и счётчиков
/add — мастер добавления нового прибора
/ping — тест связи с серверами
TXT;

    public static function httpGet(string $url, array $headers = [], int $timeout = 3, int $connectTimeout = 4): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            Storage::log('cURL Error (GET ' . self::redactUrlForLog($url) . '): ' . $err);
            return [0, ['ok' => false, 'errors' => [['error_message' => $err]]]];
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$code, json_decode((string) $body, true)];
    }

    public static function httpPostJson(string $url, array $payload, array $headers = [], int $timeout = 3, int $connectTimeout = 4): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_USERAGENT => 'TeleofisBot/1.0',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                $headers
            ),
        ]);
        $data = curl_exec($ch);
        if ($data === false) {
            $err = curl_error($ch);
            Storage::log('cURL Error (POST ' . self::redactUrlForLog($url) . '): ' . $err);
            return [0, ['ok' => false, 'errors' => [['error_message' => $err]]]];
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$code, json_decode((string) $data, true)];
    }

    private static function redactUrlForLog(string $url): string
    {
        return (string) preg_replace(
            '#(https://api\\.telegram\\.org/bot)[^/]+#',
            '$1[REDACTED]',
            $url
        );
    }

    public static function tgApi(string $method, array $params, string $token): array
    {
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        [$code, $resp] = self::httpPostJson($url, $params);
        return $resp ?? ['ok' => false];
    }

    public static function sendMessage(string $chatId, string $text, string $token, ?array $replyMarkup = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }
        self::tgApi('sendMessage', $params, $token);
    }

    public static function answerCallbackQuery(string $callbackQueryId, string $token, string $text = ''): void
    {
        self::tgApi('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], $token);
    }

    public function buildMainReplyKeyboard(string $chatId, string $prefix = '📍 '): array
    {
        return $this->keyboardBuilder->buildMainReplyKeyboard($chatId, $prefix);
    }

    public static function buildCancelReplyKeyboard(): array
    {
        return KeyboardBuilder::buildCancelReplyKeyboard();
    }

    public static function buildChannelChoiceInlineKeyboard(): array
    {
        return KeyboardBuilder::buildChannelChoiceInlineKeyboard();
    }

    public static function buildSkipChannelInlineKeyboard(): array
    {
        return KeyboardBuilder::buildSkipChannelInlineKeyboard();
    }

    public static function buildDeviceKeyboard(string $serialOrId, bool $isAdded = false): array
    {
        return KeyboardBuilder::buildDeviceKeyboard($serialOrId, $isAdded);
    }

    public static function buildDiagnosticKeyboard(string $serialOrId): array
    {
        return KeyboardBuilder::buildDiagnosticKeyboard($serialOrId);
    }

    public static function buildDiagSubKeyboard(string $serialOrId): array
    {
        return KeyboardBuilder::buildDiagSubKeyboard($serialOrId);
    }

    public static function buildEditDeviceKeyboard(string $serialOrId, bool $isFluo = false): array
    {
        return KeyboardBuilder::buildEditDeviceKeyboard($serialOrId, $isFluo);
    }

    public static function buildEditChannelChoiceKeyboard(string $serialOrId): array
    {
        return KeyboardBuilder::buildEditChannelChoiceKeyboard($serialOrId);
    }

    public static function buildSkipInitInlineKeyboard(int|string $channel): array
    {
        return KeyboardBuilder::buildSkipInitInlineKeyboard($channel);
    }
}
