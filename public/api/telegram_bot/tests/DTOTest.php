<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

use TelegramBot\DTO\DeviceDTO;
use TelegramBot\DTO\MeterReadingDTO;
use TelegramBot\DTO\TelegramUpdateDTO;

class DTOTest
{
    public static function run(): void
    {
        echo "\n🧪 1. Тестирование DTO компонентов...\n";

        // DeviceDTO
        $devData = [
            'name' => 'Fluo Test',
            'device_id' => '2e50bc92-6c87-4b64-b22e-e96e7997476f',
            'initial_values' => ['1' => 0.12]
        ];
        $dto = DeviceDTO::fromArray($devData, '8527038');
        TestRunner::assertEquals('Fluo Test', $dto->name, 'DeviceDTO name mapping');
        TestRunner::assertEquals('2e50bc92-6c87-4b64-b22e-e96e7997476f', $dto->deviceId, 'DeviceDTO deviceId mapping');
        TestRunner::assertEquals('8527038', $dto->serialNumber, 'DeviceDTO serialNumber fallback');
        TestRunner::assertEquals(0.12, $dto->initialValues['1'], 'DeviceDTO initialValues mapping');

        // MeterReadingDTO
        $reading = new MeterReadingDTO(val: 4.23, date: '2026-08-13T14:05:00', channelNumber: 2);
        TestRunner::assertEquals(4.23, $reading->val, 'MeterReadingDTO val');
        TestRunner::assertEquals('2026-08-13T14:05:00', $reading->date, 'MeterReadingDTO date');
        TestRunner::assertEquals(2, $reading->channelNumber, 'MeterReadingDTO channelNumber');

        // TelegramUpdateDTO text message
        $textUpdate = [
            'update_id' => 100,
            'message' => [
                'chat' => ['id' => 999],
                'text' => '  /start  '
            ]
        ];
        $textDto = TelegramUpdateDTO::fromArray($textUpdate);
        TestRunner::assert($textDto !== null, 'TelegramUpdateDTO parsing text update');
        TestRunner::assertEquals('/start', $textDto->text, 'TelegramUpdateDTO text trimmed');
        TestRunner::assertEquals('999', $textDto->chatId, 'TelegramUpdateDTO chatId');
        TestRunner::assert(!$textDto->isCallbackQuery, 'TelegramUpdateDTO isNotCallbackQuery');

        // TelegramUpdateDTO callback query
        $cbUpdate = [
            'update_id' => 101,
            'callback_query' => [
                'id' => 'cb_123',
                'message' => ['chat' => ['id' => 888]],
                'data' => 'month_8554760'
            ]
        ];
        $cbDto = TelegramUpdateDTO::fromArray($cbUpdate);
        TestRunner::assert($cbDto !== null, 'TelegramUpdateDTO parsing callback update');
        TestRunner::assert($cbDto->isCallbackQuery, 'TelegramUpdateDTO isCallbackQuery');
        TestRunner::assertEquals('month_8554760', $cbDto->callbackData, 'TelegramUpdateDTO callbackData');
        TestRunner::assertEquals('cb_123', $cbDto->callbackQueryId, 'TelegramUpdateDTO callbackQueryId');

        // TimeZone & Date formatting tests (+3 hours / Europe/Minsk)
        $rawUtcDate = '2026-08-13T14:05:00';
        $formattedMinsk = \TelegramBot\MeterService::formatDate($rawUtcDate, 'd.m.Y H:i', 'Europe/Minsk');
        TestRunner::assertEquals('13.08.2026 17:05', $formattedMinsk, 'MeterService::formatDate adds +3 hours for UTC naive ISO');

        $rawUtcDateWithZ = '2026-08-13T14:05:00Z';
        $formattedWithZ = \TelegramBot\MeterService::formatDate($rawUtcDateWithZ, 'd.m.Y H:i', 'Europe/Minsk');
        TestRunner::assertEquals('13.08.2026 17:05', $formattedWithZ, 'MeterService::formatDate handles Z suffix with +3h');

        $emptyDate = \TelegramBot\MeterService::formatDate(null);
        TestRunner::assertEquals('—', $emptyDate, 'MeterService::formatDate handles null');
    }
}
