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
            return new self(
                updateId: (int) ($update['update_id'] ?? 0),
                chatId: (string) ($cb['message']['chat']['id'] ?? ''),
                text: '',
                isCallbackQuery: true,
                callbackData: (string) ($cb['data'] ?? ''),
                callbackQueryId: (string) ($cb['id'] ?? '')
            );
        }

        $message = $update['message'] ?? null;
        if (!$message || empty($message['text'])) {
            return null;
        }

        return new self(
            updateId: (int) ($update['update_id'] ?? 0),
            chatId: (string) ($message['chat']['id'] ?? ''),
            text: trim((string) $message['text']),
            isCallbackQuery: false
        );
    }
}
