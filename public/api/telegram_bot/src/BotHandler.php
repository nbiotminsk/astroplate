<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\Command\CommandDispatcher;
use TelegramBot\DTO\TelegramUpdateDTO;

class BotHandler
{
    public function __construct(public readonly Container $container) {}

    public function handleUpdate(array|TelegramUpdateDTO $update): void
    {
        $dto = $update instanceof TelegramUpdateDTO ? $update : TelegramUpdateDTO::fromArray($update);
        if ($dto === null) {
            return;
        }

        /** @var CommandDispatcher $dispatcher */
        $dispatcher = $this->container->get(CommandDispatcher::class);
        $dispatcher->dispatch($dto, $this->container->config);
    }

    public function checkConfig(): void
    {
        $config = $this->container->config;
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
    public function runWebhook(): void
    {
        $this->checkConfig();
        $config = $this->container->config;

        $secret = $config['webhook_secret'] ?? '';
        if ($secret !== '' && ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== $secret) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $raw = file_get_contents('php://input');
        $update = json_decode((string) $raw, true);
        if (is_array($update)) {
            $this->handleUpdate($update);
        }
        http_response_code(200);
        echo 'ok';
    }

    /** Режим Long-polling. Запуск из CLI. */
    public function runPolling(): void
    {
        $this->checkConfig();
        $config = $this->container->config;
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
                $this->handleUpdate($update);
            }
        }
    }
}
