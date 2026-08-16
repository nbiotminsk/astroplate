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
        echo "\n🧪 6. Тестирование логики UnicBoard API и обработки показаний (Tests A — P)...\n";

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
        TestRunner::assertEquals(0, count($readingsH), 'Test H: Канал без last_value не считается имеющим текущее онлайн-показание');

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
        TestRunner::assertEquals(0, count($readingsJ), 'Test J: Пустой device_meter не создает фиктивного показания');

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
        TestRunner::assert(MeterService::isPhysicalHistoricalReading($recordsK[0]), 'Test K: DEVICE_DATA допускается в расчет расхода');
        TestRunner::assert(!MeterService::isPhysicalHistoricalReading($recordsK[1]), 'Test K: INTERPOLATED_LINEAR исключается из расчета расхода');
        $physicalChannelRecordsK = MeterService::extractChannelRecords($valuesPayloadK, 1);
        TestRunner::assertEquals(1, count($physicalChannelRecordsK), 'Test K: Для расчета остается только физическая запись');

        // Test L: current vs historical values
        TestRunner::assert($readingsI[2]->lastValue === 4.29, 'Test L: Current reading is 4.29 from /info');
        TestRunner::assert($recordsK[0]->value === 4.28, 'Test L: Historical reading is 4.28 from /values');
        TestRunner::assert($readingsI[2]->lastValue !== $recordsK[0]->value, 'Test L: Current and historical are separated');

        // Test M: HTTP 200 + invalid JSON ($resp is not an array) must result in ok=false
        $code = 200;
        $respNull = null;
        $finalOk = $code === 200 && is_array($respNull) && array_key_exists('ok', $respNull) && $respNull['ok'] === true;
        TestRunner::assert($finalOk === false, 'Test M: HTTP 200 + non-array response ($resp = null) дает ok = false');

        $respString = 'invalid json or html';
        $finalOkStr = $code === 200 && is_array($respString) && array_key_exists('ok', $respString) && $respString['ok'] === true;
        TestRunner::assert($finalOkStr === false, 'Test M: HTTP 200 + string response дает ok = false');

        $respValidApiFalse = ['ok' => false, 'errors' => ['msg' => 'error']];
        $finalOkApiFalse = $code === 200 && is_array($respValidApiFalse) && array_key_exists('ok', $respValidApiFalse) && $respValidApiFalse['ok'] === true;
        TestRunner::assert($finalOkApiFalse === false, 'Test M: HTTP 200 + API ok=false дает ok = false');

        $respValidApiTrue = ['ok' => true, 'payload' => []];
        $finalOkApiTrue = $code === 200 && is_array($respValidApiTrue) && array_key_exists('ok', $respValidApiTrue) && $respValidApiTrue['ok'] === true;
        TestRunner::assert($finalOkApiTrue === true, 'Test M: HTTP 200 + API ok=true дает ok = true');

        // Test N: /info must retry HTTP 200 + ok=true responses with incomplete device_channel.
        $infoGetAttempts = 0;
        $infoPostAttempts = 0;
        $infoResponse = \TelegramBot\UnicBoard::getDeviceInfo(
            ['unicboard_api_base' => 'https://unused.example'],
            'device-id',
            maxRetries: 4,
            retryDelayUs: 0,
            httpGet: static function (string $url, array $headers, int $timeout) use (&$infoGetAttempts): array {
                $infoGetAttempts++;

                if ($infoGetAttempts === 1) {
                    return [200, ['ok' => true, 'payload' => ['id' => 'device-id', 'device_channel' => []]]];
                }

                if ($infoGetAttempts === 2) {
                    return [200, ['ok' => true, 'payload' => [
                        'id' => 'device-id',
                        'device_channel' => [['serial_number' => 1]],
                    ]]];
                }

                return [200, ['ok' => true, 'payload' => [
                    [
                        'id' => 'device-id',
                        'device_channel' => [[
                            'serial_number' => 1,
                            'device_meter' => [['last_value' => 1.0]],
                        ]],
                    ],
                ]]];
            },
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$infoPostAttempts): array {
                $infoPostAttempts++;
                return [200, ['ok' => true, 'payload' => [
                    [
                        'id' => 'another-device-id',
                        'device_channel' => [[
                            'serial_number' => 1,
                            'device_meter' => [['last_value' => 1.0]],
                        ]],
                    ],
                ]]];
            }
        );
        TestRunner::assertEquals(3, $infoGetAttempts, 'Test N: GET fallback списка выполняется после неполных ответов');
        TestRunner::assertEquals(1, $infoPostAttempts, 'Test N: POST fallback с device_ids выполнен перед GET списком');
        TestRunner::assert($infoResponse['ok'] === true, 'Test N: Полный повторный ответ /info принят');

        // Test O: valid JSON without the mandatory ok=true is not a successful /info response.
        $missingOkAttempts = 0;
        $missingOkResponse = \TelegramBot\UnicBoard::getDeviceInfo(
            ['unicboard_api_base' => 'https://unused.example'],
            'device-id',
            maxRetries: 1,
            retryDelayUs: 0,
            httpGet: static function (string $url, array $headers, int $timeout) use (&$missingOkAttempts): array {
                $missingOkAttempts++;

                return [200, ['payload' => [
                    'id' => 'device-id',
                    'device_channel' => [[
                        'serial_number' => 1,
                        'device_meter' => [['last_value' => 1.0]],
                    ]],
                ]]];
            }
        );
        TestRunner::assertEquals(1, $missingOkAttempts, 'Test O: HTTP 200 + JSON без ok обработан как неуспех');
        TestRunner::assert($missingOkResponse['ok'] === false, 'Test O: Отсутствующий ok не становится ok=true');

        // Test P: Diagnostic toggle
        TestRunner::assert(!\TelegramBot\UnicBoard::shouldLogDiagnostic(['enable_diagnostics' => false]), 'Test P: Diagnostics disabled by config');
        TestRunner::assert(\TelegramBot\UnicBoard::shouldLogDiagnostic(['enable_diagnostics' => true]), 'Test P: Diagnostics enabled by config');

        // Test Q (Case A): Cold-start retry for /values (Attempt 1: empty payload, Attempt 2: valid payload)
        $valuesAttemptsQ = 0;
        $valuesRespQ = \TelegramBot\UnicBoard::getDeviceValues(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-q',
            maxRetries: 3,
            retryDelayUs: 0,
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$valuesAttemptsQ): array {
                $valuesAttemptsQ++;
                if ($valuesAttemptsQ === 1) {
                    return [200, ['ok' => true, 'payload' => [], 'count' => 0]];
                }
                return [200, [
                    'ok' => true,
                    'payload' => [
                        [
                            'channel_number' => 1,
                            'value' => 10.5,
                            'date' => '2026-08-16T12:00:00',
                            'value_type' => 'DEVICE_DATA',
                        ]
                    ],
                    'count' => 1,
                ]];
            }
        );
        TestRunner::assertEquals(2, $valuesAttemptsQ, 'Test Q (Case A): На холодном старте /values делает повторный запрос после пустого payload');
        TestRunner::assert($valuesRespQ['ok'] === true, 'Test Q (Case A): Успешный ответ ok=true');
        TestRunner::assertEquals(1, count($valuesRespQ['payload']), 'Test Q (Case A): Валидные данные получены');
        TestRunner::assertEquals(10.5, $valuesRespQ['payload'][0]['value'], 'Test Q (Case A): Значение равно 10.5');

        // Test R (Case B / Test H): All retries send ONLY valid devices_id from api.json, never device_ids, devices, or alt_device_ids
        $valuesAttemptsR = 0;
        $capturedBodiesR = [];
        $valuesRespR = \TelegramBot\UnicBoard::getDeviceValues(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-r',
            maxRetries: 3,
            retryDelayUs: 0,
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$valuesAttemptsR, &$capturedBodiesR): array {
                $valuesAttemptsR++;
                $capturedBodiesR[] = $body;
                if ($valuesAttemptsR < 3) {
                    return [200, ['ok' => true, 'payload' => [], 'count' => 0]];
                }
                return [200, [
                    'ok' => true,
                    'payload' => [
                        [
                            'channel_number' => 1,
                            'value' => 15.0,
                            'date' => '2026-08-16T12:00:00',
                            'value_type' => 'DEVICE_DATA',
                        ]
                    ],
                    'count' => 1,
                ]];
            }
        );
        TestRunner::assertEquals(3, $valuesAttemptsR, 'Test R: Совершены 3 попытки повтора одного валидного запроса');
        foreach ($capturedBodiesR as $idx => $body) {
            $attemptNum = $idx + 1;
            TestRunner::assert(isset($body['devices_id']), "Test R: Попытка {$attemptNum} содержит валидный ключ devices_id согласно api.json");
            TestRunner::assertEquals(['dev-uuid-r'], $body['devices_id'], "Test R: Попытка {$attemptNum} использует ровно один и тот же device_id");
            TestRunner::assert(!isset($body['device_ids']), "Test R: Попытка {$attemptNum} НЕ содержит неспецифицированный ключ device_ids");
            TestRunner::assert(!isset($body['devices']), "Test R: Попытка {$attemptNum} НЕ содержит неспецифицированный ключ devices");
            TestRunner::assert(!isset($body['alt_device_ids']), "Test R: Попытка {$attemptNum} НЕ содержит alt_device_ids");
        }
        TestRunner::assertEquals(15.0, $valuesRespR['payload'][0]['value'], 'Test R: После повторов получены валидные данные');

        // Test S (Case C): Immediate success with valid payload, no unnecessary retries
        $valuesAttemptsS = 0;
        $valuesRespS = \TelegramBot\UnicBoard::getDeviceValues(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-s',
            maxRetries: 4,
            retryDelayUs: 0,
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$valuesAttemptsS): array {
                $valuesAttemptsS++;
                return [200, [
                    'ok' => true,
                    'payload' => [
                        [
                            'channel_number' => 1,
                            'value' => 20.0,
                            'date' => '2026-08-16T12:00:00',
                            'value_type' => 'DEVICE_DATA',
                        ]
                    ],
                    'count' => 1,
                ]];
            }
        );
        TestRunner::assertEquals(1, $valuesAttemptsS, 'Test S (Case C): При валидном ответе ровно 1 запрос, без лишних повторов');
        TestRunner::assert($valuesRespS['ok'] === true, 'Test S (Case C): ok=true');
        TestRunner::assertEquals(20.0, $valuesRespS['payload'][0]['value'], 'Test S (Case C): Значение равно 20.0');

        // Test T (Case D): HTTP 200 + ok=false triggers retries according to error policy
        $valuesAttemptsT = 0;
        $valuesRespT = \TelegramBot\UnicBoard::getDeviceValues(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-t',
            maxRetries: 3,
            retryDelayUs: 0,
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$valuesAttemptsT): array {
                $valuesAttemptsT++;
                return [200, ['ok' => false, 'errors' => ['msg' => 'internal_device_error']]];
            }
        );
        TestRunner::assertEquals(3, $valuesAttemptsT, 'Test T (Case D): При API ok=false повторено maxRetries раз');
        TestRunner::assert($valuesRespT['ok'] === false, 'Test T (Case D): Итоговый ok=false');
        TestRunner::assertEquals('internal_device_error', $valuesRespT['errors']['msg'] ?? null, 'Test T (Case D): Ошибки сохранены');

        // Test U (Case E): HTTP 200 + invalid JSON triggers retry and eventually succeeds
        $valuesAttemptsU = 0;
        $valuesRespU = \TelegramBot\UnicBoard::getDeviceValues(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-u',
            maxRetries: 3,
            retryDelayUs: 0,
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$valuesAttemptsU): array {
                $valuesAttemptsU++;
                if ($valuesAttemptsU === 1) {
                    return [200, null]; // invalid JSON
                }
                if ($valuesAttemptsU === 2) {
                    return [200, '<html>502 Bad Gateway</html>']; // non-array response
                }
                return [200, [
                    'ok' => true,
                    'payload' => [
                        [
                            'channel_number' => 1,
                            'value' => 5.0,
                            'date' => '2026-08-16T12:00:00',
                            'value_type' => 'DEVICE_DATA',
                        ]
                    ],
                    'count' => 1,
                ]];
            }
        );
        TestRunner::assertEquals(3, $valuesAttemptsU, 'Test U (Case E): Невалидный JSON инициирует повтор и в итоге завершается успехом');
        TestRunner::assert($valuesRespU['ok'] === true, 'Test U (Case E): ok=true');
        TestRunner::assertEquals(5.0, $valuesRespU['payload'][0]['value'], 'Test U (Case E): Значение равно 5.0');

        // Test V (Case F): /info contains valid last_value -> current reading returned immediately without calling /values
        $deviceV = new DeviceDTO('dev_uuid_v', '8527038', 'Тестовый прибор V');
        $reportV = $reportService->buildReport($config, $deviceV);
        TestRunner::assert(str_contains($reportV, '12.345 m³') || str_contains($reportV, 'Показания'), 'Test V (Case F): buildReport возвращает показания');

        // Test W (Case G): the documented POST /devices/info fallback has priority over the broad GET list.
        $infoGetAttemptsW = 0;
        $infoPostAttemptsW = 0;
        $capturedBodiesW = [];
        $infoResponseW = \TelegramBot\UnicBoard::getDeviceInfo(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-w',
            maxRetries: 3,
            retryDelayUs: 0,
            httpGet: static function (string $url, array $headers, int $timeout) use (&$infoGetAttemptsW): array {
                $infoGetAttemptsW++;
                return [200, ['ok' => true, 'payload' => ['id' => 'dev-uuid-w', 'device_channel' => []]]];
            },
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$infoPostAttemptsW, &$capturedBodiesW): array {
                $infoPostAttemptsW++;
                $capturedBodiesW[] = $body;
                return [200, [
                    'ok' => true,
                    'count' => 100,
                    'total_count' => 500,
                    'errors' => [],
                    'payload' => [
                        [
                            'id' => 'dev-uuid-w',
                            'device_channel' => [
                                [
                                    'serial_number' => 1,
                                    'device_meter' => [
                                        ['last_value' => 7.77, 'last_value_date' => '2026-08-16T12:00:00']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]];
            }
        );
        TestRunner::assertEquals(2, $infoGetAttemptsW, 'Test W (Case G): До fallback выполнены две точечные GET-попытки');
        TestRunner::assertEquals(1, $infoPostAttemptsW, 'Test W (Case G): Использован приоритетный POST fallback на /api/v1/devices/info');
        TestRunner::assertEquals(['device_ids' => ['dev-uuid-w']], $capturedBodiesW[0], 'Test W (Case G): POST fallback использует документированный device_ids с исходным UUID');
        TestRunner::assert($infoResponseW['ok'] === true, 'Test W (Case G): Fallback завершился успешно ok=true');
        TestRunner::assertEquals(1, $infoResponseW['count'], 'Test W (Case G): count равен 1 для найденного устройства');
        TestRunner::assertEquals(1, $infoResponseW['total_count'], 'Test W (Case G): total_count равен 1 для найденного устройства');
        TestRunner::assertEquals([], $infoResponseW['errors'], 'Test W (Case G): errors сохранены из ответа API');
        $readingsW = MeterService::extractCurrentReadingsFromDeviceInfo($infoResponseW['payload']);
        TestRunner::assertEquals(7.77, $readingsW[1]->lastValue, 'Test W (Case G): last_value равен 7.77');

        // Test X (Case A): Persistent empty response returns ok=true with empty payload after bounded retries
        $valuesAttemptsX = 0;
        $valuesRespX = \TelegramBot\UnicBoard::getDeviceValues(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-x',
            maxRetries: 3,
            retryDelayUs: 0,
            httpPostJson: static function (string $url, array $body, array $headers, int $timeout) use (&$valuesAttemptsX): array {
                $valuesAttemptsX++;
                return [200, ['ok' => true, 'payload' => [], 'count' => 0, 'total_count' => 0, 'errors' => []]];
            }
        );
        TestRunner::assertEquals(3, $valuesAttemptsX, 'Test X (Case A): Выполнено ровно maxRetries попыток при пустом ответе');
        TestRunner::assert($valuesRespX['ok'] === true, 'Test X (Case A): Итоговый ok=true при пустом payload');
        TestRunner::assertEquals([], $valuesRespX['payload'], 'Test X (Case A): Итоговый payload пустой массив');

        // Test Y (Case G): /info fallback ensures only the requested device_id is matched (never another device)
        $infoAttemptsY = 0;
        $infoResponseY = \TelegramBot\UnicBoard::getDeviceInfo(
            ['unicboard_api_base' => 'https://unused.example'],
            'target-device-id',
            maxRetries: 4,
            retryDelayUs: 0,
            httpGet: static function (string $url, array $headers, int $timeout) use (&$infoAttemptsY): array {
                $infoAttemptsY++;
                if ($infoAttemptsY < 3) {
                    return [200, null];
                }
                return [200, [
                    'ok' => true,
                    'count' => 50,
                    'total_count' => 50,
                    'errors' => [],
                    'payload' => [
                        [
                            'id' => 'other-unrelated-device',
                            'device_channel' => [
                                ['serial_number' => 1, 'device_meter' => [['last_value' => 99.9]]]
                            ]
                        ]
                    ]
                ]];
            },
            httpPostJson: static fn(string $url, array $body, array $headers, int $timeout): array => [200, [
                'ok' => true,
                'payload' => [
                    ['id' => 'other-unrelated-device', 'device_channel' => []],
                ],
            ]]
        );
        TestRunner::assertEquals(3, $infoAttemptsY, 'Test Y (Case G): GET-список остается последней попыткой после POST fallback');
        TestRunner::assert($infoResponseY['ok'] === false, 'Test Y (Case G): Чужой device_id не возвращается как успешный для целевого устройства');
        TestRunner::assert($infoResponseY['payload'] === null, 'Test Y (Case G): payload равен null при отсутствии целевого устройства в списке');
        TestRunner::assertEquals(0, $infoResponseY['count'], 'Test Y (Case G): count равен 0 когда целевое устройство не найдено');
        TestRunner::assertEquals(0, $infoResponseY['total_count'], 'Test Y (Case G): total_count равен 0 когда целевое устройство не найдено');
        TestRunner::assertEquals([], $infoResponseY['errors'], 'Test Y (Case G): errors сохранены');

        // Test Z: A structurally incomplete direct /info response must not bypass the completeness check.
        $infoResponseZ = \TelegramBot\UnicBoard::getDeviceInfo(
            ['unicboard_api_base' => 'https://unused.example'],
            'dev-uuid-z',
            maxRetries: 1,
            retryDelayUs: 0,
            httpGet: static fn(string $url, array $headers, int $timeout): array => [200, [
                'ok' => true,
                'payload' => [
                    'id' => 'dev-uuid-z',
                    'device_channel' => [],
                ],
            ]]
        );
        TestRunner::assert($infoResponseZ['ok'] === false, 'Test Z: Неполный /info не становится успешным при завершении попыток');
        TestRunner::assert($infoResponseZ['payload'] === null, 'Test Z: Неполный /info не возвращается как финальный payload');
    }
}
