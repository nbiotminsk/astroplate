<?php

declare(strict_types=1);

namespace TelegramBot\DTO;

readonly class MeterReadingDTO
{
    public function __construct(
        public float $val,
        public string $date,
        public int $channelNumber = 1
    ) {}
}
