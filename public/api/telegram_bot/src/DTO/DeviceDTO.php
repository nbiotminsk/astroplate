<?php

declare(strict_types=1);

namespace TelegramBot\DTO;

readonly class DeviceDTO
{
    public function __construct(
        public string $deviceId,
        public string $serialNumber,
        public string $name,
        public array $initialValues = []
    ) {}

    public static function fromArray(array $data, string $fallbackSerial = ''): self
    {
        $serial = (string) ($data['serial_number'] ?? $fallbackSerial);
        $name = (string) ($data['name'] ?? ($serial !== '' ? "Устройство {$serial}" : 'Прибор'));

        return new self(
            deviceId: (string) ($data['device_id'] ?? $data['id'] ?? ''),
            serialNumber: $serial,
            name: $name,
            initialValues: (array) ($data['initial_values'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'device_id' => $this->deviceId,
            'serial_number' => $this->serialNumber,
            'initial_values' => $this->initialValues,
        ];
    }
}
