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
TXT;

    public static function httpGet(string $url, array $headers = [], int $timeout = 5, int $connectTimeout = 2): array
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
            $err = 'cURL Error (GET ' . $url . '): ' . curl_error($ch);
            Storage::log($err);
            return [0, null];
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$code, json_decode((string) $body, true)];
    }

    public static function httpPostJson(string $url, array $payload, array $headers = [], int $timeout = 5, int $connectTimeout = 2): array
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
            $err = 'cURL Error (POST ' . $url . '): ' . curl_error($ch);
            Storage::log($err);
            return [0, null];
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

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
            $addr = is_array($data) ? ($data['address'] ?? $data['name'] ?? "Счетчик {$serial}") : (string) $data;
            $prefix = (str_starts_with($addr, '📍') || str_starts_with($addr, '💧')) ? '' : '📍 ';
            $buttons[] = ['text' => "{$prefix}{$addr}"];
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

    public static function buildCancelReplyKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '❌ Отмена']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    public static function buildChannelChoiceInlineKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '1️⃣ и 2️⃣ (Оба входа)', 'callback_data' => 'wiz_ch_1_2']],
                [['text' => '1️⃣ Только 1-й вход', 'callback_data' => 'wiz_ch_1']],
                [['text' => '2️⃣ Только 2-й вход', 'callback_data' => 'wiz_ch_2']],
                [['text' => '❌ Отмена', 'callback_data' => 'wiz_cancel']],
            ]
        ];
    }

    public static function buildSkipChannelInlineKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '⏩ Пропустить этот вход', 'callback_data' => 'wiz_skip']],
                [['text' => '❌ Отмена', 'callback_data' => 'wiz_cancel']],
            ]
        ];
    }

    public static function buildDeviceKeyboard(string $serialOrId, bool $isAdded = false): array
    {
        $addRemoveBtn = $isAdded
            ? ['text' => '❌ Удалить счетчик', 'callback_data' => 'del_' . $serialOrId]
            : ['text' => '➕ Сохранить в Мои счетчики', 'callback_data' => 'add_' . $serialOrId];

        $buttons = [
            [
                ['text' => '📅 Архив за месяц', 'callback_data' => 'month_' . $serialOrId],
            ],
            [
                ['text' => '⚙️ Диагностика', 'callback_data' => 'diag_' . $serialOrId],
            ],
        ];

        if ($isAdded) {
            $buttons[] = [
                ['text' => '✏️ Изменить', 'callback_data' => 'edit_' . $serialOrId],
            ];
        }

        $buttons[] = [
            $addRemoveBtn,
        ];

        return [
            'inline_keyboard' => $buttons,
        ];
    }

    public static function buildDiagnosticKeyboard(string $serialOrId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 Назад к прибору', 'callback_data' => 'back_dev_' . $serialOrId],
                ],
            ],
        ];
    }

    public static function buildEditDeviceKeyboard(string $serialOrId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📍 Название / Адрес', 'callback_data' => 'edit_addr_' . $serialOrId],
                ],
                [
                    ['text' => '🏷️ Номера счётчиков', 'callback_data' => 'edit_meters_' . $serialOrId],
                ],
                [
                    ['text' => '🔢 Начальные показания', 'callback_data' => 'edit_init_' . $serialOrId],
                ],
                [
                    ['text' => '🔌 Количество каналов', 'callback_data' => 'edit_ch_' . $serialOrId],
                ],
                [
                    ['text' => '🔙 Назад к прибору', 'callback_data' => 'back_dev_' . $serialOrId],
                ],
            ],
        ];
    }

    public static function buildEditChannelChoiceKeyboard(string $serialOrId): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '1️⃣ и 2️⃣ (Оба входа)', 'callback_data' => 'set_ch_' . $serialOrId . '_1_2']],
                [['text' => '1️⃣ Только 1-й вход', 'callback_data' => 'set_ch_' . $serialOrId . '_1']],
                [['text' => '2️⃣ Только 2-й вход', 'callback_data' => 'set_ch_' . $serialOrId . '_2']],
                [['text' => '🔙 Назад к настройкам', 'callback_data' => 'edit_' . $serialOrId]],
            ],
        ];
    }
}
