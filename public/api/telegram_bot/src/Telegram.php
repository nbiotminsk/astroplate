<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\Repository\UserMeterRepositoryInterface;

class Telegram
{
    public function __construct(
        private ?UserMeterRepositoryInterface $userMeterRepo = null
    ) {}

    public const TO_CMD = <<<TXT
Команды бота:
/start — запустить бота и открыть меню
/add СЕРИЙНЫЙ_№ UUID НАЗВАНИЕ — добавить новый прибор в систему
Пример: <code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f Fluo</code>
/init СЕРИЙНЫЙ_№ КАНАЛ ЗНАЧЕНИЕ — задать/изменить начальное показание счетчика
Пример: <code>/init 8527038 1 0.12</code>
/my — список моих сохраненных счетчиков
TXT;

    public static function httpGet(string $url, array $headers = [], int $timeout = 15, int $connectTimeout = 5): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            error_log('cURL Error (GET ' . $url . '): ' . curl_error($ch));
            @curl_close($ch);
            return [0, null];
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        @curl_close($ch);

        return [$code, json_decode((string) $body, true)];
    }

    public static function httpPostJson(string $url, array $payload, array $headers = [], int $timeout = 15, int $connectTimeout = 5): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                $headers
            ),
        ]);
        $data = curl_exec($ch);
        if ($data === false) {
            error_log('cURL Error (POST ' . $url . '): ' . curl_error($ch));
            @curl_close($ch);
            return [0, null];
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        @curl_close($ch);

        return [$code, json_decode((string) $data, true)];
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

    public function buildMainReplyKeyboard(string $chatId): array
    {
        $meters = $this->userMeterRepo ? $this->userMeterRepo->getMetersByChatId($chatId) : Storage::getUserMeters($chatId);
        $keyboard = [];

        $buttons = [];
        foreach ($meters as $serial => $data) {
            $name = is_array($data) ? ($data['name'] ?? "Счетчик {$serial}") : (string) $data;
            $buttons[] = ['text' => "💧 {$name} ({$serial})"];
            if (count($buttons) === 2) {
                $keyboard[] = $buttons;
                $buttons = [];
            }
        }
        if (!empty($buttons)) {
            $keyboard[] = $buttons;
        }

        $keyboard[] = [
            ['text' => '➕ Добавить счетчик'],
            ['text' => '📋 Мои счетчики']
        ];

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    public static function buildDeviceKeyboard(string $serialOrId, bool $isAdded = false): array
    {
        $addRemoveBtn = $isAdded
            ? ['text' => '❌ Удалить счетчик', 'callback_data' => 'del_' . $serialOrId]
            : ['text' => '➕ Сохранить в Мои счетчики', 'callback_data' => 'add_' . $serialOrId];

        return [
            'inline_keyboard' => [
                [
                    ['text' => '📅 Архив за месяц', 'callback_data' => 'month_' . $serialOrId],
                ],
                [
                    $addRemoveBtn
                ]
            ]
        ];
    }
}
