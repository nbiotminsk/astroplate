<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\DeviceDTO;
use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Repository\DeviceRepositoryInterface;
use TelegramBot\Repository\UserMeterRepositoryInterface;
use TelegramBot\Storage;
use TelegramBot\Telegram;
use TelegramBot\UnicBoard;

class AddDeviceCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private DeviceRepositoryInterface $deviceRepo,
        private UserMeterRepositoryInterface $userMeterRepo,
        private ?ReportService $reportService = null
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        if ($update->isCallbackQuery) {
            return str_starts_with($update->callbackData, 'wiz_');
        }

        $text = $update->text;
        if ($text === '➕ Добавить счетчик' || $text === '/add' || str_starts_with($text, '/add ')) {
            return true;
        }

        if ($text === '❌ Отмена' || $text === '/cancel') {
            return Storage::getUserState($update->chatId) !== null;
        }

        $state = Storage::getUserState($update->chatId);
        if ($state !== null) {
            $step = (string) ($state['step'] ?? '');
            return !str_starts_with($step, 'EDIT_');
        }

        return false;
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $mainKey = $this->telegram->buildMainReplyKeyboard($chatId);
        $cancelKey = Telegram::buildCancelReplyKeyboard();

        // 1. Отмена процесса
        if (
            (!$update->isCallbackQuery && ($update->text === '❌ Отмена' || $update->text === '/cancel')) ||
            ($update->isCallbackQuery && $update->callbackData === 'wiz_cancel')
        ) {
            Storage::clearUserState($chatId);
            if ($update->isCallbackQuery) {
                Telegram::answerCallbackQuery($update->callbackQueryId, $token, 'Отменено');
            }
            Telegram::sendMessage($chatId, "Добавление счетчика отменено.", $token, $mainKey);
            return;
        }

        // 2. Обработка Callback от кнопок выбора каналов
        if ($update->isCallbackQuery && str_starts_with($update->callbackData, 'wiz_ch_')) {
            $state = Storage::getUserState($chatId);
            if ($state === null) {
                Telegram::answerCallbackQuery($update->callbackQueryId, $token);
                return;
            }

            Telegram::answerCallbackQuery($update->callbackQueryId, $token);
            $choice = $update->callbackData;

            if ($choice === 'wiz_ch_1_2') {
                $state['active_channels'] = [1, 2];
            } elseif ($choice === 'wiz_ch_1') {
                $state['active_channels'] = [1];
            } elseif ($choice === 'wiz_ch_2') {
                $state['active_channels'] = [2];
            } else {
                $state['active_channels'] = [1, 2];
            }

            if (in_array(1, $state['active_channels'], true)) {
                $state['step'] = 'WAITING_METER_CH1';
                Storage::setUserState($chatId, $state);
                Telegram::sendMessage(
                    $chatId,
                    "Введите номер счётчика на <b>1-м входе</b> и текущие показания с циферблата (через пробел):\n\n<i>Пример: <code>12345678 142.5</code> или просто <code>12345678</code></i>",
                    $token,
                    $cancelKey
                );
            } else {
                $state['step'] = 'WAITING_METER_CH2';
                Storage::setUserState($chatId, $state);
                Telegram::sendMessage(
                    $chatId,
                    "Введите номер счётчика на <b>2-м входе</b> и текущие показания с циферблата (через пробел):\n\n<i>Пример: <code>87654321 4.3</code> или просто <code>87654321</code></i>",
                    $token,
                    $cancelKey
                );
            }
            return;
        }

        // 3. Обработка пропущенного входа (wiz_skip)
        if ($update->isCallbackQuery && $update->callbackData === 'wiz_skip') {
            $state = Storage::getUserState($chatId);
            if ($state === null) {
                Telegram::answerCallbackQuery($update->callbackQueryId, $token);
                return;
            }
            Telegram::answerCallbackQuery($update->callbackQueryId, $token);

            if ($state['step'] === 'WAITING_METER_CH1') {
                if (in_array(2, $state['active_channels'] ?? [], true)) {
                    $state['step'] = 'WAITING_METER_CH2';
                    Storage::setUserState($chatId, $state);
                    Telegram::sendMessage(
                        $chatId,
                        "Введите номер счётчика на <b>2-м входе</b> и текущие показания с циферблата (через пробел):\n\n<i>Пример: <code>87654321 4.3</code></i>",
                        $token,
                        $cancelKey
                    );
                } else {
                    $this->finishWizard($chatId, $state, $config);
                }
            } elseif ($state['step'] === 'WAITING_METER_CH2') {
                $this->finishWizard($chatId, $state, $config);
            }
            return;
        }

        $text = trim($update->text);

        // 4. Прямой legacy формат: /add SERIAL UUID [NAME]
        if (str_starts_with($text, '/add ')) {
            $parts = preg_split('/\s+/', $text);
            if (count($parts) >= 3 && preg_match('/^[0-9a-f-]{36}$/i', $parts[2])) {
                $serial = $parts[1];
                $uuid = $parts[2];
                $name = count($parts) >= 4 ? implode(' ', array_slice($parts, 3)) : "Счетчик {$serial}";

                $this->deviceRepo->registerDevice($serial, $uuid, $name, [], $name);
                $this->meterService->fetchAndSaveInitialValues($config, $serial, $uuid);
                $this->userMeterRepo->addMeter($chatId, $serial, $name, $uuid, $name);

                $newKey = $this->telegram->buildMainReplyKeyboard($chatId);
                Telegram::sendMessage($chatId, "🎉 Новый прибор успешно зарегистрирован!\n\n• <b>№</b>: <code>{$serial}</code>\n• <b>UUID</b>: <code>{$uuid}</code>", $token, $newKey);
                return;
            }
        }

        // 5. Запуск мастера (кнопка «➕ Добавить счетчик» или /add)
        if ($text === '➕ Добавить счетчик' || $text === '/add') {
            Storage::setUserState($chatId, [
                'step' => 'WAITING_SERIAL',
                'channels_config' => [],
            ]);
            Telegram::sendMessage(
                $chatId,
                "Введите номер модема (7 цифр, указан на корпусе прибора):\n\n<i>Пример: <code>8554760</code></i>",
                $token,
                $cancelKey
            );
            return;
        }

        $state = Storage::getUserState($chatId);

        // Если состояния нет — показываем подсказку
        if ($state === null) {
            Storage::setUserState($chatId, [
                'step' => 'WAITING_SERIAL',
                'channels_config' => [],
            ]);
            Telegram::sendMessage(
                $chatId,
                "Введите номер модема (7 цифр, указан на корпусе прибора):",
                $token,
                $cancelKey
            );
            return;
        }

        $currentStep = $state['step'] ?? 'WAITING_SERIAL';

        // 6. Шаг 1: Ввод серийного номера модема
        if ($currentStep === 'WAITING_SERIAL') {
            $serialInput = preg_replace('/[^0-9a-zA-Z-]/', '', $text);
            if ($serialInput === '') {
                Telegram::sendMessage($chatId, "Пожалуйста, введите корректный номер модема (цифры с корпуса):", $token, $cancelKey);
                return;
            }

            // Ищем прибор в UnicBoard API
            $foundDevice = null;
            $allRemote = UnicBoard::getAllDevices($config, 100);
            if (($allRemote['ok'] ?? false) && !empty($allRemote['payload'])) {
                foreach ($allRemote['payload'] as $item) {
                    $mfgSerial = (string) ($item['manufacturer_serial_number'] ?? '');
                    $mac = (string) ($item['data_gateway_network_device']['mac'] ?? '');
                    $devId = (string) ($item['id'] ?? '');

                    if ($mfgSerial === $serialInput || $mac === $serialInput || $devId === $serialInput) {
                        $foundDevice = $item;
                        break;
                    }
                }
            }

            // Проверяем локальные устройства, если в API не нашли
            if (!$foundDevice) {
                $local = $this->meterService->deviceLookup($config, $serialInput);
                if ($local) {
                    $foundDevice = [
                        'id' => $local->deviceId,
                        'manufacturer_serial_number' => $local->serialNumber ?: $serialInput,
                        'device_channel' => [
                            ['serial_number' => 1],
                            ['serial_number' => 2],
                        ],
                    ];
                }
            }

            if (!$foundDevice) {
                Telegram::sendMessage(
                    $chatId,
                    "❌ Модем с номером <code>{$serialInput}</code> не найден в системе UnicBoard.\n\nПроверьте правильность номера или обратитесь в службу поддержки.",
                    $token,
                    $cancelKey
                );
                return;
            }

            $isFluo = MeterService::isFluoDevice($foundDevice);
            $uuid = (string) ($foundDevice['id'] ?? '');
            $serial = (string) ($foundDevice['manufacturer_serial_number'] ?? $serialInput);
            $channels = $foundDevice['device_channel'] ?? [];
            $chCount = $isFluo ? 1 : (count($channels) > 0 ? count($channels) : 2);

            $state['step'] = 'WAITING_ADDRESS';
            $state['serial'] = $serial;
            $state['uuid'] = $uuid;
            $state['ch_count'] = $chCount;
            $state['is_fluo'] = $isFluo;
            if ($isFluo) {
                $state['active_channels'] = [1];
            }
            Storage::setUserState($chatId, $state);

            $devTitle = $isFluo ? "Счётчик Fluo <b>№ {$serial}</b>" : "Модем <b>№ {$serial}</b>";
            Telegram::sendMessage(
                $chatId,
                "✅ {$devTitle} найден!\n\n📍 Введите адрес установки (например: <code>ул. Кольцова 8 корпус 2 кв. 74</code>):",
                $token,
                $cancelKey
            );
            return;
        }

        // 7. Шаг 2: Ввод адреса установки
        if ($currentStep === 'WAITING_ADDRESS') {
            $address = trim($text);
            if ($address === '') {
                Telegram::sendMessage($chatId, "Пожалуйста, введите адрес установки:", $token, $cancelKey);
                return;
            }

            $state['address'] = $address;
            $isFluo = !empty($state['is_fluo']);
            $chCount = (int) ($state['ch_count'] ?? ($isFluo ? 1 : 2));

            if ($isFluo) {
                $state['active_channels'] = [1];
                $state['step'] = 'WAITING_METER_CH1';
                Storage::setUserState($chatId, $state);

                Telegram::sendMessage(
                    $chatId,
                    "📍 <b>Адрес:</b> {$address}\n\n🔢 Введите текущие показания с циферблата счётчика Fluo (в м³):\n\n<i>Пример: <code>0.12</code> или просто <code>0</code></i>",
                    $token,
                    $cancelKey
                );
            } elseif ($chCount > 1) {
                $state['step'] = 'WAITING_CHANNELS_SELECT';
                Storage::setUserState($chatId, $state);

                Telegram::sendMessage(
                    $chatId,
                    "📍 <b>Адрес:</b> {$address}\n\nКакие входы модема задействованы?",
                    $token,
                    Telegram::buildChannelChoiceInlineKeyboard()
                );
            } else {
                $state['active_channels'] = [1];
                $state['step'] = 'WAITING_METER_CH1';
                Storage::setUserState($chatId, $state);

                Telegram::sendMessage(
                    $chatId,
                    "Введите номер счётчика воды и текущие показания с циферблата (через пробел):\n\n<i>Пример: <code>12345678 142.5</code> или просто <code>12345678</code></i>",
                    $token,
                    $cancelKey
                );
            }
            return;
        }

        // 8. Шаг 3: Ввод номера счётчика для 1-го входа
        if ($currentStep === 'WAITING_METER_CH1') {
            $cleanText = trim(str_replace(',', '.', $text));
            $isFluo = !empty($state['is_fluo']);

            if ($isFluo) {
                $userInit = null;
                $meterNum = (string) ($state['serial'] ?? '');

                $state['channels_config']['1'] = [
                    'meter_number' => $meterNum,
                    'user_initial' => $userInit,
                    'base_api_value' => null,
                ];
                $this->finishWizard($chatId, $state, $config);
                return;
            }

            $parts = preg_split('/[\s]+/', $cleanText);
            $meterNum = $parts[0] ?? '';
            $userInit = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : null;

            if (count($parts) === 1 && is_numeric($parts[0]) && str_contains($parts[0], '.')) {
                $userInit = (float) $parts[0];
                $meterNum = '';
            }

            $state['channels_config']['1'] = [
                'meter_number' => $meterNum,
                'user_initial' => $userInit,
                'base_api_value' => null,
            ];

            if (in_array(2, $state['active_channels'] ?? [], true)) {
                $state['step'] = 'WAITING_METER_CH2';
                Storage::setUserState($chatId, $state);

                Telegram::sendMessage(
                    $chatId,
                    "Введите номер счётчика на <b>2-м входе</b> и текущие показания с циферблата (через пробел):\n\n<i>Пример: <code>87654321 4.3</code> или просто <code>87654321</code></i>",
                    $token,
                    $cancelKey
                );
            } else {
                $this->finishWizard($chatId, $state, $config);
            }
            return;
        }

        // 9. Шаг 4: Ввод номера счётчика для 2-го входа
        if ($currentStep === 'WAITING_METER_CH2') {
            $cleanText = trim(str_replace(',', '.', $text));
            $parts = preg_split('/[\s]+/', $cleanText);
            $meterNum = $parts[0] ?? '';
            $userInit = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : null;

            if (count($parts) === 1 && is_numeric($parts[0]) && str_contains($parts[0], '.')) {
                $userInit = (float) $parts[0];
                $meterNum = '';
            }

            $state['channels_config']['2'] = [
                'meter_number' => $meterNum,
                'user_initial' => $userInit,
                'base_api_value' => null,
            ];

            $this->finishWizard($chatId, $state, $config);
        }
    }

    private function finishWizard(string $chatId, array $state, array $config): void
    {
        $serial = (string) ($state['serial'] ?? '');
        $uuid = (string) ($state['uuid'] ?? '');
        $address = (string) ($state['address'] ?? "Счетчик {$serial}");
        $isFluo = !empty($state['is_fluo']);
        $activeChannels = $isFluo ? [1] : (array) ($state['active_channels'] ?? [1, 2]);
        $channelsConfig = (array) ($state['channels_config'] ?? []);

        $initialValues = [];
        foreach ($channelsConfig as $ch => $cfg) {
            if (isset($cfg['user_initial']) && $cfg['user_initial'] !== null) {
                $initialValues[(string) $ch] = (float) $cfg['user_initial'];
            }
        }

        $this->deviceRepo->registerDevice(
            $serial,
            $uuid,
            $address,
            $initialValues,
            $address,
            $activeChannels,
            $channelsConfig
        );

        $this->userMeterRepo->addMeter($chatId, $serial, $address, $uuid, $address);
        Storage::clearUserState($chatId);

        $token = $config['telegram_token'];
        $mainKey = $this->telegram->buildMainReplyKeyboard($chatId);
        $devKey = Telegram::buildDeviceKeyboard($serial, true);

        $summaryLines = [];
        if ($isFluo) {
            $summaryLines[] = "• Тип: <b>Умный счётчик Fluo</b> (№ <code>{$serial}</code>)";
        } else {
            foreach ($activeChannels as $ch) {
                $chStr = (string) $ch;
                $meterNum = $channelsConfig[$chStr]['meter_number'] ?? '';
                $meterPart = $meterNum !== '' ? " (Счётчик № {$meterNum})" : '';
                if (isset($initialValues[$chStr])) {
                    $initVal = number_format((float) $initialValues[$chStr], 2, '.', '');
                    $summaryLines[] = "• Вход {$ch}{$meterPart}: начальные <b>{$initVal} m³</b>";
                } else {
                    $summaryLines[] = "• Вход {$ch}{$meterPart}: <b>по показаниям модема</b>";
                }
            }
        }
        $summary = implode("\n", $summaryLines);

        $devTypeLabel = $isFluo ? 'Счётчик Fluo' : 'Счётчик';
        $serialLabel = $isFluo ? '№' : 'Модем: №';

        $savedDevice = $this->deviceRepo->findBySerialOrName($serial) ?? new DeviceDTO($uuid, $serial, $address, $initialValues, $address, $activeChannels, $channelsConfig);
        try {
            $report = $this->reportService->buildReport($config, $savedDevice);
            Telegram::sendMessage(
                $chatId,
                "🎉 <b>{$devTypeLabel} успешно добавлен в систему!</b>\n\n{$report}",
                $token,
                $devKey
            );
        } catch (\Throwable) {
            Telegram::sendMessage(
                $chatId,
                "🎉 <b>{$devTypeLabel} успешно добавлен в систему!</b>\n\n📍 <b>{$address}</b>\n🆔 {$serialLabel} <b>{$serial}</b>\n\n{$summary}",
                $token,
                $devKey
            );
        }

        // Обновляем нижнюю клавиатуру, заменяя кнопку «❌ Отмена» на актуальный список приборов
        Telegram::sendMessage(
            $chatId,
            "<i>Главное меню обновлено. Выберите адрес внизу:</i>",
            $token,
            $mainKey
        );
    }
}
