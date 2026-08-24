<?php

declare(strict_types=1);

namespace TelegramBot\DTO;

readonly class HistoricalValueDTO
{
    public function __construct(
        public int $channelNumber,
        public string $date,
        public float $value,
        public ?float $valueRaw = null,
        public ?float $lastValue = null,
        public ?string $lastValueDate = null,
        public string $valueType = 'DEVICE_DATA',
        public string $journalDataType = 'CURRENT',
        public string $kind = 'COMMON_CONSUMED',
        public string $meterId = '',
        public string $deviceId = '',
        public int $tariffNumber = -1,
        public ?string $dateCreated = null
    ) {}
}
