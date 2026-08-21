<?php

declare(strict_types=1);

namespace TelegramBot\DTO;

readonly class DeviceDTO
{
    public function __construct(
        public string $deviceId,
        public string $serialNumber,
        public string $name,
        public array $initialValues = [],
        public ?string $address = null,
        public ?array $activeChannels = null,
        public ?array $channels = null
    ) {}

    public static function fromArray(array $data, string $fallbackSerial = ''): self
    {
        $serial = (string) ($data['serial_number'] ?? $fallbackSerial);
        $address = isset($data['address']) && $data['address'] !== '' ? (string) $data['address'] : null;
        $name = (string) ($data['name'] ?? ($address ?? ($serial !== '' ? "Устройство {$serial}" : 'Прибор')));

        return new self(
            deviceId: (string) ($data['device_id'] ?? $data['id'] ?? ''),
            serialNumber: $serial,
            name: $name,
            initialValues: (array) ($data['initial_values'] ?? []),
            address: $address,
            activeChannels: isset($data['active_channels']) && is_array($data['active_channels'])
                ? array_values(array_map('intval', $data['active_channels']))
                : null,
            channels: isset($data['channels']) && is_array($data['channels'])
                ? $data['channels']
                : null
        );
    }

    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'device_id' => $this->deviceId,
            'serial_number' => $this->serialNumber,
            'initial_values' => $this->initialValues,
        ];
        if ($this->address !== null) {
            $out['address'] = $this->address;
        }
        if ($this->activeChannels !== null) {
            $out['active_channels'] = $this->activeChannels;
        }
        if ($this->channels !== null) {
            $out['channels'] = $this->channels;
        }

        return $out;
    }
}
