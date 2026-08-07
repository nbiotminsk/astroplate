<?php
/*
 * Конфигурация бота UnicBoard.
 *
 * Секреты (токены) читаются из файла .env — см. .env.example.
 * Список устройств задаётся ниже, в разделе 'devices'.
 */

declare(strict_types=1);

require __DIR__ . '/env.php';
load_env(__DIR__ . '/.env');

return [
    // Токен бота из @BotFather (переменная TELEGRAM_BOT_TOKEN)
    'telegram_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',

    // Токен API UnicBoard (переменная UNICBOARD_API_TOKEN)
    'unicboard_token' => getenv('UNICBOARD_API_TOKEN') ?: '',

    // Базовый URL API UnicBoard
    'unicboard_api_base' => getenv('UNICBOARD_API_BASE') ?: 'https://api.public.data-aggregator.unicboard.by',

    /* Список доступных устройств.
     * Ключ — числовой ID (введите в чат), value — данные:
     *   'name'      — название прибора
     *   'device_id' — UUID устройства в API
     */
    'devices' => [
        8527038 => [
            'name' => 'Fluo',
            'device_id' => '2e50bc92-6c87-4b64-b22e-e96e7997476f',
        ],
        8524390 => [
            'name' => 'Юпитер',
            'device_id' => '420de7d0-5e14-453d-8ad3-5a1dc3729e34',
        ],
    ],
];