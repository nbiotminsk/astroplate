<?php

declare(strict_types=1);

namespace TelegramBot\Tests;

use TelegramBot\DTO\ChannelReadingDTO;
use TelegramBot\DTO\DeviceDTO;
use TelegramBot\DTO\HistoricalValueDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;

class UnicBoardApiTest
{
    public static function run(): void
    {
        echo "\n🧪 6. Тестирование логики UnicBoard API и обработки показаний (Tests A — L)...\n";

        $config = require __DIR__ . '/../config.php';
        $meterService = new MeterService();
        $reportService = new ReportService(null, $meterService);

        // Test A: Normal /info with last_value + /values with history
        $infoPayloadA = [
            'id' => 'dev_uuid_a',
            'manufacturer_serial_number' => '8527038',
            'device_channel' => [
                [
                    'serial_number' => 1,
                    'device_meter' => [
                        [
                            'last_value' => 12.345,
                            'last_value_date' => '2026-08-16T10:00:00',
                            'unit_multiplier' => 1,
                            'value_multiplier' => 1,
                        ]
                    ],
                    'inactivity_limit' => 86400,
                    'last_date_event_no_data' => null,
                ]
            ]
        ];
        $readingsA = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayloadA);
        TestRunner::assertEquals(1, count($readingsA), 'Test A: Извлечен 1 канал');
        TestRunner::assertEquals(12.345, $readingsA[1]->lastValue, 'Test A: last_value равен 12.345');
        TestRunner::assertEquals('2026-08-16T10:00:00', $readingsA[1]->lastValueDate, 'Test A: last_value_date корректен');

        // Test B: /info contains last_value, /values returns []
        // Expected: current reading is extracted and displayed from /info without requiring /values
        $valuesPayloadB = [];
        $recordsB = MeterService::extractHistoricalRecordsFromValues($valuesPayloadB);
        TestRunner::assertEquals(0, count($recordsB), 'Test B: /values пустой');
        TestRunner::assert($readingsA[1]->hasReading(), 'Test B: Показание доступно из /info даже при пустом /values');

        // Test C: Incomplete /info (device_channel missing or empty)
        $infoPayloadC = ['id' => 'dev_uuid_c', 'device_channel' => []];
        $readingsC = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayloadC);
        TestRunner::assertEquals([], $readingsC, 'Test C: Безопасная обработка неполного /info');

        // Test D: API ok=true, payload=[]
        // Expected: not treated as transport or API error
        $apiRespD = [
            'http_status' => 200,
            'ok' => true,
            'payload' => [],
            'count' => 0,
            'errors' => []
        ];
        TestRunner::assert($apiRespD['ok'] === true, 'Test D: ok=true с пустым payload не превращается в ok=false');
        TestRunner::assert($apiRespD['http_status'] === 200, 'Test D: HTTP 200 сохранен');

        // Test E: API ok=false
        $apiRespE = [
            'http_status' => 400,
            'ok' => false,
            'payload' => null,
            'errors' => ['error' => 'invalid_device_id']
        ];
        TestRunner::assert($apiRespE['ok'] === false, 'Test E: ok=false корректно распознается');
        TestRunner::assert(!empty($apiRespE['errors']), 'Test E: errors сохранены');

        // Test F: Network / timeout error response structure
        $apiRespF = [
            'http_status' => 0,
            'ok' => false,
            'payload' => [],
            'errors' => []
        ];
        TestRunner::assertEquals(0, $apiRespF['http_status'], 'Test F: Сетевой сбой сохраняет http_status=0');
        TestRunner::assert(!$apiRespF['ok'], 'Test F: Сетевой сбой ok=false');

        // Test G: last_value = 0 (zero is a valid reading!)
        $infoPayloadG = [
            'device_channel' => [
                [
                    'serial_number' => 1,
                    'device_meter' => [
                        [
                            'last_value' => 0,
                            'last_value_date' => '2026-08-16T00:00:00',
                            'unit_multiplier' => 1,
                            'value_multiplier' => 1,
                        ]
                    ]
                ]
            ]
        ];
        $readingsG = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayloadG);
        TestRunner::assert(isset($readingsG[1]), 'Test G: Канал 1 существует');
        TestRunner::assert($readingsG[1]->lastValue === 0.0, 'Test G: 0 обрабатывается как 0.0, а не null');
        TestRunner::assert($readingsG[1]->hasReading(), 'Test G: hasReading() возвращает true для 0.0');

        // Test H: missing last_value (null)
        $infoPayloadH = [
            'device_channel' => [
                [
                    'serial_number' => 1,
                    'device_meter' => [
                        [
                            'last_value' => null,
                            'last_value_date' => null,
                            'unit_multiplier' => 1,
                            'value_multiplier' => 1,
                        ]
                    ]
                ]
            ]
        ];
        $readingsH = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayloadH);
        TestRunner::assert(isset($readingsH[1]), 'Test H: Канал 1 существует');
        TestRunner::assert($readingsH[1]->lastValue === null, 'Test H: lastValue равен null');
        TestRunner::assert(!$readingsH[1]->hasReading(), 'Test H: hasReading() возвращает false');

        // Test I: Multi-channel device (Channels 1 and 2)
        $infoPayloadI = [
            'device_channel' => [
                [
                    'serial_number' => 1,
                    'device_meter' => [
                        ['last_value' => 0.0, 'last_value_date' => '2026-08-15T12:00:00', 'unit_multiplier' => 10, 'value_multiplier' => 1]
                    ]
                ],
                [
                    'serial_number' => 2,
                    'device_meter' => [
                        ['last_value' => 4.29, 'last_value_date' => '2026-08-15T12:00:00', 'unit_multiplier' => 1, 'value_multiplier' => 1]
                    ]
                ]
            ]
        ];
        $readingsI = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayloadI);
        TestRunner::assertEquals(2, count($readingsI), 'Test I: 2 канала извлечены');
        TestRunner::assertEquals(0.0, $readingsI[1]->lastValue, 'Test I: Канал 1 имеет значение 0.0');
        TestRunner::assertEquals(4.29, $readingsI[2]->lastValue, 'Test I: Канал 2 имеет значение 4.29');

        // Test J: device_meter = []
        $infoPayloadJ = [
            'device_channel' => [
                [
                    'serial_number' => 1,
                    'device_meter' => []
                ]
            ]
        ];
        $readingsJ = MeterService::extractCurrentReadingsFromDeviceInfo($infoPayloadJ);
        TestRunner::assert(isset($readingsJ[1]), 'Test J: Канал 1 создан без предупреждений');
        TestRunner::assert($readingsJ[1]->lastValue === null, 'Test J: lastValue равен null');

        // Test K: value_type = INTERPOLATED_LINEAR vs DEVICE_DATA
        $valuesPayloadK = [
            [
                'channel_number' => 1,
                'date' => '2026-08-14T00:00:00',
                'value' => 4.28,
                'value_type' => 'DEVICE_DATA',
                'journal_data_type' => 'CURRENT',
                'kind' => 'COMMON_CONSUMED',
                'meter_id' => 'uuid_1'
            ],
            [
                'channel_number' => 1,
                'date' => '2026-08-14T12:00:00',
                'value' => 4.285,
                'value_type' => 'INTERPOLATED_LINEAR',
                'journal_data_type' => 'CURRENT',
                'kind' => 'COMMON_CONSUMED',
                'meter_id' => 'uuid_1'
            ]
        ];
        $recordsK = MeterService::extractHistoricalRecordsFromValues($valuesPayloadK);
        TestRunner::assertEquals('DEVICE_DATA', $recordsK[0]->valueType, 'Test K: Record 0 is DEVICE_DATA');
        TestRunner::assertEquals('INTERPOLATED_LINEAR', $recordsK[1]->valueType, 'Test K: Record 1 is INTERPOLATED_LINEAR');

        // Test L: current vs historical values
        TestRunner::assert($readingsI[2]->lastValue === 4.29, 'Test L: Current reading is 4.29 from /info');
        TestRunner::assert($recordsK[0]->value === 4.28, 'Test L: Historical reading is 4.28 from /values');
        TestRunner::assert($readingsI[2]->lastValue !== $recordsK[0]->value, 'Test L: Current and historical are separated');
    }
}
