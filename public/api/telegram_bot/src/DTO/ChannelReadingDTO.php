<?php

declare(strict_types=1);

namespace TelegramBot\DTO;

readonly class ChannelReadingDTO
{
    public function __construct(
        public int $channelNumber,
        public ?float $lastValue,
        public ?string $lastValueDate,
        public float $unitMultiplier = 1.0,
        public float $valueMultiplier = 1.0,
        public ?int $inactivityLimit = null,
        public ?string $lastDateEventNoData = null
    ) {}

    public function hasReading(): bool
    {
        return $this->lastValue !== null;
    }

    public function isInactive(): bool
    {
        return $this->lastDateEventNoData !== null;
    }
}
