<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;

class CommandDispatcher
{
    /** @var CommandInterface[] */
    private array $commands = [];

    public function __construct(array $commands = [])
    {
        foreach ($commands as $command) {
            $this->addCommand($command);
        }
    }

    public function addCommand(CommandInterface $command): void
    {
        $this->commands[] = $command;
    }

    public function dispatch(TelegramUpdateDTO $update, array $config): bool
    {
        foreach ($this->commands as $command) {
            if ($command->supports($update)) {
                $command->handle($update, $config);
                return true;
            }
        }
        return false;
    }
}
