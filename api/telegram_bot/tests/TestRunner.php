<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

class TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;
    private static array $failures = [];

    public static function assert(bool $condition, string $message): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m {$message}\n";
        } else {
            self::$failed++;
            self::$failures[] = $message;
            echo "  \033[31m✗\033[0m {$message}\n";
        }
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        $condition = ($expected === $actual);
        if (!$condition) {
            $message .= " (Expected: " . var_export($expected, true) . ", Actual: " . var_export($actual, true) . ")";
        }
        self::assert($condition, $message);
    }

    public static function summarize(): int
    {
        echo "\n============================================================\n";
        echo "РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ:\n";
        echo "\033[32mУспешно: " . self::$passed . "\033[0m\n";
        if (self::$failed > 0) {
            echo "\033[31mОшибок: " . self::$failed . "\033[0m\n";
            foreach (self::$failures as $fail) {
                echo " - {$fail}\n";
            }
            return 1;
        }

        echo "\033[32mВСЕ ТЕСТЫ УСПЕШНО ПРОЙДЕНЫ! 🎉\033[0m\n";
        return 0;
    }
}
