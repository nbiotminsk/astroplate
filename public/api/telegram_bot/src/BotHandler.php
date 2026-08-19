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
        Storage::log("Update received: chat_id={$dto->chatId}, text=" . ($dto->text !== '' ? $dto->text : ($dto->callbackData ?? '')));
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
            $err = "Ошибка: задайте в .env: " . implode(', ', $missing);
            Storage::log($err);
            fwrite(STDERR, $err . "\n");
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
            Storage::log("Webhook 403 Forbidden: Invalid secret token");
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $raw = file_get_contents('php://input');
        $update = json_decode((string) $raw, true);
        
        // Отвечаем Telegram мгновенно, чтобы закрыть соединение
        http_response_code(200);
        echo 'ok';
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Выполняем тяжелую работу (опросы API) в фоне
        if (is_array($update)) {
            $this->handleUpdate($update);
        }
    }

    /** Режим Long-polling. Запуск из CLI. */
    public function runPolling(): void
    {
        $this->checkConfig();
        $config = $this->container->config;
        $offset = 0;
        Storage::log("Bot started (long-polling)");
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
