<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;

interface CommandInterface
{
    public function supports(TelegramUpdateDTO $update): bool;
    public function handle(TelegramUpdateDTO $update, array $config): void;
}
