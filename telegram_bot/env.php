<?php
/**
 * Загрузчик переменных окружения из .env файла.
 * Читает файл .env рядом с собой, парсит KEY=VALUE строки.
 * Не перезаписывает уже существующие переменные окружения.
 */

declare(strict_types=1);

function load_env(?string $path = null): void
{
    $path = $path ?? __DIR__ . '/.env';
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        // Пропускаем комментарии и пустые строки
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Убираем кавычки
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}