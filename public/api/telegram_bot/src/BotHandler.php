<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\Command\AddDeviceCommand;
use TelegramBot\Command\AddMeterCallback;
use TelegramBot\Command\CommandDispatcher;
use TelegramBot\Command\DelDeviceCommand;
use TelegramBot\Command\DelMeterCallback;
use TelegramBot\Command\InitMeterCommand;
use TelegramBot\Command\MeterDetailCommand;
use TelegramBot\Command\MonthArchiveCallback;
use TelegramBot\Command\MyMetersCommand;
use TelegramBot\Command\StartCommand;
use TelegramBot\DTO\TelegramUpdateDTO;

class BotHandler
{
    private static ?CommandDispatcher $dispatcher = null;

    public static function getDispatcher(): CommandDispatcher
    {
        if (self::$dispatcher === null) {
            self::$dispatcher = new CommandDispatcher([
                new MonthArchiveCallback(),
                new AddMeterCallback(),
                new DelMeterCallback(),
                new StartCommand(),
                new MyMetersCommand(),
                new AddDeviceCommand(),
                new DelDeviceCommand(),
                new InitMeterCommand(),
                new MeterDetailCommand(),
            ]);
        }
        return self::$dispatcher;
    }

    public static function handleUpdate(array|TelegramUpdateDTO $update, array $config): void
    {
        $dto = $update instanceof TelegramUpdateDTO ? $update : TelegramUpdateDTO::fromArray($update);
        if ($dto === null) {
            return;
        }

        self::getDispatcher()->dispatch($dto, $config);
    }

    public static function checkConfig(array $config): void
    {
        $missing = [];
        if (($config['telegram_token'] ?? '') === '') {
            $missing[] = 'TELEGRAM_BOT_TOKEN';
        }
        if (($config['unicboard_token'] ?? '') === '') {
            $missing[] = 'UNICBOARD_API_TOKEN';
        }
        if ($missing) {
            fwrite(STDERR, "Ошибка: задайте в .env: " . implode(', ', $missing) . "\n");
            exit(1);
        }
    }

    /** Режим Webhook. Запуск на веб-сервере. */
    public static function runWebhook(array $config): void
    {
        self::checkConfig($config);

        $secret = $config['webhook_secret'] ?? '';
        if ($secret !== '' && ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== $secret) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $raw = file_get_contents('php://input');
        $update = json_decode((string) $raw, true);
        if (is_array($update)) {
            self::handleUpdate($update, $config);
        }
        http_response_code(200);
        echo 'ok';
    }

    /** Режим Long-polling. Запуск из CLI. */
    public static function runPolling(array $config): void
    {
        self::checkConfig($config);
        $offset = 0;
        echo "Бот запущен (long-polling). Нажмите Ctrl+C для остановки.\n";

        while (true) {
            $url = "https://api.telegram.org/bot{$config['telegram_token']}/getUpdates?timeout=50&offset={$offset}";
            [$code, $resp] = Telegram::httpGet($url, [], 60, 10);
            if ($code !== 200 || !($resp['ok'] ?? false)) {
                sleep(2);
                continue;
            }

            foreach ($resp['result'] ?? [] as $update) {
                $updateId = $update['update_id'] ?? null;
                if ($updateId !== null && $updateId >= $offset) {
                    $offset = $updateId + 1;
                }
                self::handleUpdate($update, $config);
            }
        }
    }
}
