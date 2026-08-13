<?php

declare(strict_types=1);

namespace TelegramBot;

use TelegramBot\Command\AddDeviceCommand;
use TelegramBot\Command\AddMeterCallback;
use TelegramBot\Command\CommandDispatcher;
use TelegramBot\Command\DelDeviceCommand;
use TelegramBot\Command\DelMeterCallback;
use TelegramBot\Command\InitMeterCommand;
use TelegramBot\Command\MeterDetailCommand;
use TelegramBot\Command\MonthArchiveCallback;
use TelegramBot\Command\MyMetersCommand;
use TelegramBot\Command\StartCommand;
use TelegramBot\Repository\DeviceRepositoryInterface;
use TelegramBot\Repository\JsonDeviceRepository;
use TelegramBot\Repository\JsonMeterCacheRepository;
use TelegramBot\Repository\JsonUserMeterRepository;
use TelegramBot\Repository\MeterCacheRepositoryInterface;
use TelegramBot\Repository\UserMeterRepositoryInterface;

class Container
{
    private array $instances = [];
    private array $factories = [];

    public function __construct(public readonly array $config)
    {
        $this->registerServices();
    }

    private function registerServices(): void
    {
        $this->set(DeviceRepositoryInterface::class, static fn() => new JsonDeviceRepository());
        $this->set(UserMeterRepositoryInterface::class, static fn() => new JsonUserMeterRepository());
        $this->set(MeterCacheRepositoryInterface::class, static fn() => new JsonMeterCacheRepository());

        $this->set(CommandDispatcher::class, static fn() => new CommandDispatcher([
            new MonthArchiveCallback(),
            new AddMeterCallback(),
            new DelMeterCallback(),
            new StartCommand(),
            new MyMetersCommand(),
            new AddDeviceCommand(),
            new DelDeviceCommand(),
            new InitMeterCommand(),
            new MeterDetailCommand(),
        ]));
    }

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $this->instances[$id] = ($this->factories[$id])($this);
            return $this->instances[$id];
        }

        throw new \InvalidArgumentException("Service not found: {$id}");
    }
}
