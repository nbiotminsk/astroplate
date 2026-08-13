<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

use TelegramBot\Command\CommandDispatcher;
use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\DTO\DeviceDTO;
use TelegramBot\ReportService;
use TelegramBot\Repository\JsonUserMeterRepository;
use TelegramBot\UnicBoard;

class EdgeCasesTest
{
    public static function run(): void
    {
        echo "\n🧪 5. Тестирование граничных случаев и надежности (Edge Cases & Resiliency)...\n";

        $config = require __DIR__ . '/../config.php';

        // 1. Неизвестная команда / Ненайденный прибор
        $unknownUpdate = new TelegramUpdateDTO(99, 'chat_unknown', 'какая_то_неизвестная_команда');
        $unknownDev = \TelegramBot\MeterService::deviceLookup($config, 'какая_то_неизвестная_команда');
        TestRunner::assert($unknownDev === null, 'Неизвестная команда корректно возвращает null при поиске прибора');

        // 2. Обработка пустого ответа API (payload = [])
        $emptyDevice = new DeviceDTO('fake_empty_id', '999991', 'Тест Пустого Прибора');
        $reportEmpty = ReportService::buildReport($config, $emptyDevice);
        TestRunner::assert(str_contains($reportEmpty, 'нет данных'), 'Пустой ответ API не обрушивает бот и содержит «нет данных»');

        $monthEmpty = ReportService::buildMonthReport($config, $emptyDevice);
        TestRunner::assert(str_contains($monthEmpty, 'записей не найдено') || str_contains($monthEmpty, 'Архив за текущий месяц'), 'Пустой архив API обрабатывается без ошибок');

        // 3. Таймаут или сбой HTTP-запроса (code = 0)
        $timeoutValues = UnicBoard::getDeviceValues($config, 'fake_non_existent_uuid', 10, null, 1);
        TestRunner::assert(is_array($timeoutValues), 'Запрос с таймаутом/неверным UUID возвращает валидную структуру массива');
        TestRunner::assert(isset($timeoutValues['ok']), 'Структура ответа содержит флаг ok');

        // 4. Изоляция данных двух пользователей (User A и User B)
        $userRepo = new JsonUserMeterRepository();
        $userA = 'user_100001';
        $userB = 'user_100002';

        // Очищаем тестовых пользователей
        $userRepo->removeMeter($userA, '8527038');
        $userRepo->removeMeter($userB, '8554760');

        // Добавляем прибор Пользователю A
        $userRepo->addMeter($userA, '8527038', 'Счетчик Пользователя А');
        // Добавляем прибор Пользователю B
        $userRepo->addMeter($userB, '8554760', 'Счетчик Пользователя Б');

        $metersA = $userRepo->getMetersByChatId($userA);
        $metersB = $userRepo->getMetersByChatId($userB);

        TestRunner::assert(isset($metersA['8527038']) && !isset($metersA['8554760']), 'Пользователь A видит только свои счетчики');
        TestRunner::assert(isset($metersB['8554760']) && !isset($metersB['8527038']), 'Пользователь B видит только свои счетчики');

        // Удаляем у Пользователя A — проверяем что Пользователь B не затронут
        $userRepo->removeMeter($userA, '8527038');
        $metersAAfter = $userRepo->getMetersByChatId($userA);
        $metersBAfter = $userRepo->getMetersByChatId($userB);

        TestRunner::assert(!isset($metersAAfter['8527038']), 'Счетчик пользователя A успешно удален');
        TestRunner::assert(isset($metersBAfter['8554760']), 'Счетчик пользователя B не пострадал при удалении у A (Изоляция 100%)');

        // 5. Проверка защиты от дубликатов при повторном добавлении
        $userRepo->addMeter($userA, '8527038', 'Старое Имя');
        $userRepo->addMeter($userA, '8527038', 'Новое Имя');
        $metersDup = $userRepo->getMetersByChatId($userA);

        TestRunner::assertEquals(1, count($metersDup), 'Повторное добавление счетчика не создает дубликатов');
        TestRunner::assertEquals('Новое Имя', $metersDup['8527038'], 'Повторное добавление обновляет имя прибора');

        // 6. Один и тот же счетчик может одновременно принадлежать разным пользователям
        $sharedSerial = '8527038';
        $userRepo->addMeter($userA, $sharedSerial, 'Счетчик Fluo у Пользователя A');
        $userRepo->addMeter($userB, $sharedSerial, 'Счетчик Fluo у Пользователя B');

        $sharedMetersA = $userRepo->getMetersByChatId($userA);
        $sharedMetersB = $userRepo->getMetersByChatId($userB);

        TestRunner::assert(isset($sharedMetersA[$sharedSerial]), 'Счетчик 8527038 успешно добавлен Пользователю A');
        TestRunner::assert(isset($sharedMetersB[$sharedSerial]), 'Счетчик 8527038 одновременно добавлен Пользователю B');
        TestRunner::assertEquals('Счетчик Fluo у Пользователя A', $sharedMetersA[$sharedSerial], 'Пользователь A имеет свое имя для прибора');
        TestRunner::assertEquals('Счетчик Fluo у Пользователя B', $sharedMetersB[$sharedSerial], 'Пользователь B имеет свое имя для прибора');

        // Чистим тестовые данные
        $userRepo->removeMeter($userA, $sharedSerial);
        $userRepo->removeMeter($userB, $sharedSerial);
    }
}
