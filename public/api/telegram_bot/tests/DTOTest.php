<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

use TelegramBot\DTO\ChannelReadingDTO;
use TelegramBot\DTO\DeviceDTO;
use TelegramBot\DTO\HistoricalValueDTO;
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

        // ChannelReadingDTO
        $chReading = new ChannelReadingDTO(
            channelNumber: 1,
            lastValue: 0.0,
            lastValueDate: '2026-08-16T10:00:00',
            unitMultiplier: 10.0,
            valueMultiplier: 1.0,
            inactivityLimit: 86400,
            lastDateEventNoData: '2026-08-16T03:00:00'
        );
        TestRunner::assertEquals(1, $chReading->channelNumber, 'ChannelReadingDTO channelNumber');
        TestRunner::assertEquals(0.0, $chReading->lastValue, 'ChannelReadingDTO lastValue handles 0.0');
        TestRunner::assert($chReading->hasReading(), 'ChannelReadingDTO hasReading is true');
        TestRunner::assert(
            !$chReading->isInactive(strtotime('2026-08-16T10:10:00Z')),
            'ChannelReadingDTO не считает старое событие no-data текущей неактивностью'
        );
        TestRunner::assert(
            $chReading->isInactive(strtotime('2026-08-17T10:00:01Z')),
            'ChannelReadingDTO учитывает inactivity_limit относительно last_value_date'
        );

        // HistoricalValueDTO
        $histValue = new HistoricalValueDTO(
            channelNumber: 2,
            date: '2026-08-15T00:00:00',
            value: 4.29,
            valueRaw: 4.29,
            valueType: 'DEVICE_DATA',
            journalDataType: 'CURRENT',
            kind: 'COMMON_CONSUMED',
            meterId: 'uuid-123'
        );
        TestRunner::assertEquals(2, $histValue->channelNumber, 'HistoricalValueDTO channelNumber');
        TestRunner::assertEquals(4.29, $histValue->value, 'HistoricalValueDTO value');
        TestRunner::assertEquals('DEVICE_DATA', $histValue->valueType, 'HistoricalValueDTO valueType');

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

        // DeviceDTO extended fields (address, activeChannels, channels)
        $extendedDevData = [
            'name' => 'ул. Кольцова 8',
            'address' => 'ул. Кольцова 8 корпус 2 кв. 74',
            'device_id' => 'ae0bf621-39e3-47e5-9126-52ec6e90d242',
            'serial_number' => '8554760',
            'active_channels' => [2],
            'channels' => [
                '2' => [
                    'meter_number' => '87654321',
                    'user_initial' => 142.5,
                    'base_api_value' => 4.3,
                ],
            ],
        ];
        $extDto = DeviceDTO::fromArray($extendedDevData);
        TestRunner::assertEquals('ул. Кольцова 8 корпус 2 кв. 74', $extDto->address, 'DeviceDTO address mapping');
        TestRunner::assertEquals([2], $extDto->activeChannels, 'DeviceDTO activeChannels mapping');
        TestRunner::assertEquals('87654321', $extDto->channels['2']['meter_number'], 'DeviceDTO channel meter_number mapping');

        // MeterService::calculateDisplayValue (Scenario 2: what you see is what you write)
        // User saw 142.50 on meter dial when API was at 4.30.
        // If current API is still 4.30, display must be exactly 142.50.
        $calcCurrent = \TelegramBot\MeterService::calculateDisplayValue(4.30, 142.50, 4.30);
        TestRunner::assertEquals(142.50, $calcCurrent, 'calculateDisplayValue: at moment of binding displays exact user initial');

        // If API increments to 5.80 (+1.50 m³ water consumed), display must be 144.00.
        $calcNew = \TelegramBot\MeterService::calculateDisplayValue(5.80, 142.50, 4.30);
        TestRunner::assertEquals(144.00, $calcNew, 'calculateDisplayValue: accumulates only new delta');

        // Format to 2 decimal places check
        $formatted = number_format($calcNew, 2, '.', '') . ' m³';
        TestRunner::assertEquals('144.00 m³', $formatted, 'Format to 2 decimal places');
    }
}
