<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\Repository\UserMeterRepositoryInterface;

class KeyboardBuilder
{
    public function __construct(
        private ?UserMeterRepositoryInterface $userMeterRepo = null
    ) {}

    public function buildMainReplyKeyboard(string $chatId, string $prefix = '📍 '): array
    {
        $meters = $this->userMeterRepo ? $this->userMeterRepo->getMetersByChatId($chatId) : Storage::getUserMeters($chatId);
        $keyboard = [];

        $buttons = [];
        foreach ($meters as $serial => $data) {
            $addr = is_array($data) ? ($data['address'] ?? $data['name'] ?? "Счетчик {$serial}") : (string) $data;
            $label = "{$prefix}{$addr}";
            $buttons[] = ['text' => $label];
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
        $keyboard[] = [
            ['text' => '⚡ Тест сервера']
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
                ['text' => '🔄 Опросить / Обновить', 'callback_data' => 'back_dev_' . $serialOrId],
                ['text' => '📅 Архив за месяц',     'callback_data' => 'month_' . $serialOrId],
            ],
        ];

        if ($isAdded) {
            $buttons[] = [
                ['text' => '⚙️ Диагностика', 'callback_data' => 'diag_' . $serialOrId],
                ['text' => '✏️ Изменить',    'callback_data' => 'edit_' . $serialOrId],
            ];
        } else {
            $buttons[] = [
                ['text' => '⚙️ Диагностика', 'callback_data' => 'diag_' . $serialOrId],
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
                [['text' => '📊 Каналы / Импульсы', 'callback_data' => 'diag_ch_'    . $serialOrId]],
                [['text' => '🔋 Батарея',            'callback_data' => 'diag_bat_'   . $serialOrId]],
                [['text' => '🌡️ Температура',         'callback_data' => 'diag_temp_'  . $serialOrId]],
                [['text' => '🕒 Часы',                'callback_data' => 'diag_clock_' . $serialOrId]],
                [['text' => '🔙 Назад к прибору',     'callback_data' => 'back_dev_'   . $serialOrId]],
            ],
        ];
    }

    public static function buildDiagSubKeyboard(string $serialOrId): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🔙 К диагностике', 'callback_data' => 'diag_' . $serialOrId]],
            ],
        ];
    }

    public static function buildEditDeviceKeyboard(string $serialOrId, bool $isFluo = false): array
    {
        $buttons = [
            [
                ['text' => '📍 Название / Адрес', 'callback_data' => 'edit_addr_' . $serialOrId],
            ],
        ];

        if (!$isFluo) {
            $buttons[] = [
                ['text' => '🏷️ Номера счётчиков', 'callback_data' => 'edit_meters_' . $serialOrId],
            ];
            $buttons[] = [
                ['text' => '🔢 Начальные показания', 'callback_data' => 'edit_init_' . $serialOrId],
            ];
            $buttons[] = [
                ['text' => '🔌 Количество каналов', 'callback_data' => 'edit_ch_' . $serialOrId],
            ];
        }

        $buttons[] = [
            ['text' => '🔙 Назад к прибору', 'callback_data' => 'back_dev_' . $serialOrId],
        ];

        return [
            'inline_keyboard' => $buttons,
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

    public static function buildSkipInitInlineKeyboard(int|string $channel): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '⏭️ Пропустить ввод показаний', 'callback_data' => 'wiz_skip_init_' . $channel]
                ]
            ]
        ];
    }
}
