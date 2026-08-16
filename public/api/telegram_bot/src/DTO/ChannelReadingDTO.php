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

    /**
     * Канал неактивен, только если последнее показание старше лимита неактивности.
     * lastDateEventNoData — историческое событие и не является текущим статусом.
     */
    public function isInactive(?int $now = null): bool
    {
        if ($this->inactivityLimit === null || $this->inactivityLimit <= 0 || $this->lastValueDate === null) {
            return false;
        }

        try {
            $lastValueAt = preg_match('/[Zz]|[\+\-]\d{2}:?\d{2}$/', $this->lastValueDate)
                ? new \DateTimeImmutable($this->lastValueDate)
                : new \DateTimeImmutable($this->lastValueDate, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return ($now ?? time()) - $lastValueAt->getTimestamp() > $this->inactivityLimit;
    }
}
