<?php

declare(strict_types=1);

namespace TelegramBot\DTO;

readonly class TelegramUpdateDTO
{
    public function __construct(
        public int $updateId,
        public string $chatId,
        public string $text,
        public bool $isCallbackQuery = false,
        public string $callbackData = '',
        public string $callbackQueryId = ''
    ) {}

    public static function fromArray(array $update): ?self
    {
        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $chatId = (string) ($cb['message']['chat']['id'] ?? ($cb['from']['id'] ?? ''));
            return new self(
                updateId: (int) ($update['update_id'] ?? 0),
                chatId: $chatId,
                text: '',
                isCallbackQuery: true,
                callbackData: (string) ($cb['data'] ?? ''),
                callbackQueryId: (string) ($cb['id'] ?? '')
            );
        }

        $message = $update['message'] ?? null;
        if (!$message || !isset($message['text'])) {
            return null;
        }

        $chatId = (string) ($message['chat']['id'] ?? ($message['from']['id'] ?? ''));
        return new self(
            updateId: (int) ($update['update_id'] ?? 0),
            chatId: $chatId,
            text: trim((string) $message['text']),
            isCallbackQuery: false
        );
    }
}
