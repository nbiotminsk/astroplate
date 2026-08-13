<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

$config = require __DIR__ . '/../config.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'TelegramBot\\';
    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

require_once __DIR__ . '/TestRunner.php';
require_once __DIR__ . '/DTOTest.php';
require_once __DIR__ . '/RepositoryTest.php';
require_once __DIR__ . '/CommandTest.php';
require_once __DIR__ . '/ContainerTest.php';
require_once __DIR__ . '/EdgeCasesTest.php';

echo "============================================================\n";
echo "🚀 ЗАПУСК ТЕХНОЛОГИЧЕСКОГО ТЕСТОВОГО НАБОРА TELEGRAM-БОТА\n";
echo "============================================================\n";

DTOTest::run();
RepositoryTest::run();
CommandTest::run();
ContainerTest::run();
EdgeCasesTest::run();

$exitCode = TestRunner::summarize();
exit($exitCode);
