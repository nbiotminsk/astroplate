<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\ReportService;
use TelegramBot\Repository\DeviceRepositoryInterface;
use TelegramBot\Repository\UserMeterRepositoryInterface;
use TelegramBot\Storage;
use TelegramBot\Telegram;
use TelegramBot\UnicBoard;

class EditDeviceCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private ReportService $reportService,
        private DeviceRepositoryInterface $deviceRepo,
        private UserMeterRepositoryInterface $userMeterRepo
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        if ($update->isCallbackQuery) {
            $data = $update->callbackData;
            return str_starts_with($data, 'edit_') ||
                   str_starts_with($data, 'set_ch_') ||
                   str_starts_with($data, 'back_dev_');
        }

        $state = Storage::getUserState($update->chatId);
        if ($state !== null && isset($state['step']) && str_starts_with((string) $state['step'], 'EDIT_')) {
            return true;
        }

        return false;
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $mainKey = $this->telegram->buildMainReplyKeyboard($chatId);
        $cancelKey = Telegram::buildCancelReplyKeyboard();

        // 1. Отмена редактирования
        if (!$update->isCallbackQuery && ($update->text === '❌ Отмена' || $update->text === '/cancel')) {
            Storage::clearUserState($chatId);
            Telegram::sendMessage($chatId, "Редактирование отменено.", $token, $mainKey);
            return;
        }

        // 2. Обработка Callback-запросов
        if ($update->isCallbackQuery) {
            $cbId = $update->callbackQueryId;
            $data = $update->callbackData;

            // Кнопка [🔙 Назад к прибору]
            if (str_starts_with($data, 'back_dev_')) {
                $serial = str_replace('back_dev_', '', $data);
                $device = $this->meterService->deviceLookup($config, $serial);
                Telegram::answerCallbackQuery($cbId, $token);
                if ($device) {
                    $report = $this->reportService->buildReport($config, $device);
                    $key = Telegram::buildDeviceKeyboard($serial, true);
                    Telegram::sendMessage($chatId, $report, $token, $key);
                }
                return;
            }

            // Кнопка [✏️ Изменить] -> Открытие главного меню настроек
            if (preg_match('/^edit_(\d+)$/', $data, $m)) {
                $serial = $m[1];
                $device = $this->meterService->deviceLookup($config, $serial);
                Telegram::answerCallbackQuery($cbId, $token);
                if (!$device) {
                    Telegram::sendMessage($chatId, "Прибор не найден.", $token, $mainKey);
                    return;
                }

                $addr = $device->address ?: $device->name;
                $text = "⚙️ <b>Настройки прибора № {$serial}</b>\n(📍 {$addr})\n\nВыберите, что хотите изменить:";
                $key = Telegram::buildEditDeviceKeyboard($serial);
                Telegram::sendMessage($chatId, $text, $token, $key);
                return;
            }

            // Опция 1: [📍 Название / Адрес]
            if (str_starts_with($data, 'edit_addr_')) {
                $serial = str_replace('edit_addr_', '', $data);
                Storage::setUserState($chatId, [
                    'step' => 'EDIT_ADDRESS',
                    'serial' => $serial,
                ]);
                Telegram::answerCallbackQuery($cbId, $token);
                Telegram::sendMessage(
                    $chatId,
                    "📍 Введите новый адрес установки для прибора <b>№ {$serial}</b>:\n\n<i>Пример: <code>ул. Кольцова 8 корпус 2 кв. 74</code></i>",
                    $token,
                    $cancelKey
                );
                return;
            }

            // Опция 2: [🏷️ Номера счётчиков]
            if (str_starts_with($data, 'edit_meters_')) {
                $serial = str_replace('edit_meters_', '', $data);
                $device = $this->meterService->deviceLookup($config, $serial);
                Telegram::answerCallbackQuery($cbId, $token);
                if (!$device) {
                    Telegram::sendMessage($chatId, "Прибор не найден.", $token, $mainKey);
                    return;
                }

                $activeChannels = $device->activeChannels ?? [1, 2];
                Storage::setUserState($chatId, [
                    'step' => 'EDIT_METERS',
                    'serial' => $serial,
                    'active_channels' => $activeChannels,
                ]);

                if (count($activeChannels) === 1) {
                    $chNum = $activeChannels[0];
                    Telegram::sendMessage(
                        $chatId,
                        "🏷️ Введите номер физического счётчика для <b>входа {$chNum}</b>:\n\n<i>Пример: <code>87654321</code></i>",
                        $token,
                        $cancelKey
                    );
                } else {
                    Telegram::sendMessage(
                        $chatId,
                        "🏷️ Введите номера счётчиков для <b>1-го и 2-го входов</b> через пробел:\n\n<i>Пример: <code>12345678 87654321</code></i>",
                        $token,
                        $cancelKey
                    );
                }
                return;
            }

            // Опция 3: [🔢 Начальные показания]
            if (str_starts_with($data, 'edit_init_')) {
                $serial = str_replace('edit_init_', '', $data);
                $device = $this->meterService->deviceLookup($config, $serial);
                Telegram::answerCallbackQuery($cbId, $token);
                if (!$device) {
                    Telegram::sendMessage($chatId, "Прибор не найден.", $token, $mainKey);
                    return;
                }

                $activeChannels = $device->activeChannels ?? [1, 2];
                Storage::setUserState($chatId, [
                    'step' => 'EDIT_INITIAL',
                    'serial' => $serial,
                    'active_channels' => $activeChannels,
                    'uuid' => $device->deviceId,
                ]);

                if (count($activeChannels) === 1) {
                    $chNum = $activeChannels[0];
                    Telegram::sendMessage(
                        $chatId,
                        "🔢 Введите текущие показания с циферблата для <b>входа {$chNum}</b>:\n\n<i>Пример: <code>142.50</code></i>",
                        $token,
                        $cancelKey
                    );
                } else {
                    Telegram::sendMessage(
                        $chatId,
                        "🔢 Введите текущие показания с циферблатов для <b>1-го и 2-го входов</b> через пробел:\n\n<i>Пример: <code>0 142.50</code></i>",
                        $token,
                        $cancelKey
                    );
                }
                return;
            }

            // Опция 4: [🔌 Количество каналов] -> Выбор каналов
            if (str_starts_with($data, 'edit_ch_')) {
                $serial = str_replace('edit_ch_', '', $data);
                Telegram::answerCallbackQuery($cbId, $token);
                Telegram::sendMessage(
                    $chatId,
                    "🔌 Выберите активные входы модема для прибора <b>№ {$serial}</b>:",
                    $token,
                    Telegram::buildEditChannelChoiceKeyboard($serial)
                );
                return;
            }

            // Применение выбранных каналов (set_ch_{serial}_{1_2|1|2})
            if (str_starts_with($data, 'set_ch_')) {
                $parts = explode('_', str_replace('set_ch_', '', $data));
                $serial = $parts[0] ?? '';
                $chChoice = isset($parts[1]) ? implode('_', array_slice($parts, 1)) : '1_2';

                $active = ($chChoice === '1') ? [1] : (($chChoice === '2') ? [2] : [1, 2]);

                $customDevices = Storage::loadRegisteredDevices();
                if (isset($customDevices[(int) $serial])) {
                    $customDevices[(int) $serial]['active_channels'] = $active;
                    Storage::saveRegisteredDevices($customDevices);
                }

                Telegram::answerCallbackQuery($cbId, $token, "Каналы обновлены!");

                $device = $this->meterService->deviceLookup($config, $serial);
                if ($device) {
                    $report = $this->reportService->buildReport($config, $device);
                    $key = Telegram::buildDeviceKeyboard($serial, true);
                    Telegram::sendMessage($chatId, "✅ <b>Конфигурация каналов обновлена!</b>\n\n" . $report, $token, $key);
                }
                return;
            }

            return;
        }

        // 3. Обработка текстового ввода в режимах редактирования
        $state = Storage::getUserState($chatId);
        if ($state === null) {
            return;
        }

        $step = $state['step'] ?? '';
        $serial = (string) ($state['serial'] ?? '');
        $text = trim($update->text);

        // Редактирование адреса
        if ($step === 'EDIT_ADDRESS') {
            if ($text === '') {
                Telegram::sendMessage($chatId, "Пожалуйста, введите адрес:", $token, $cancelKey);
                return;
            }

            // 1. Сохраняем в registered_devices.json
            $customDevices = Storage::loadRegisteredDevices();
            $key = isset($customDevices[(int) $serial]) ? (int) $serial : (isset($customDevices[$serial]) ? $serial : (int) $serial);
            if (!isset($customDevices[$key])) {
                $customDevices[$key] = [];
            }
            $customDevices[$key]['address'] = $text;
            $customDevices[$key]['name'] = $text;
            Storage::saveRegisteredDevices($customDevices);

            // 2. Сохраняем/обновляем привязку у пользователя в user_meters.json
            Storage::addUserMeter($chatId, (string) $serial, $text, '', $text);

            // 3. Очищаем состояние ввода и обновляем клавиатуры
            Storage::clearUserState($chatId);
            $newMainKey = $this->telegram->buildMainReplyKeyboard($chatId);

            Telegram::sendMessage(
                $chatId,
                "✅ <b>Адрес установки успешно изменён!</b>\n\n📍 <b>{$text}</b> (Прибор № {$serial})\n\n<i>Выберите адрес в меню внизу для просмотра показаний.</i>",
                $token,
                $newMainKey
            );
            return;
        }

        // Редактирование номеров счётчиков
        if ($step === 'EDIT_METERS') {
            $parts = preg_split('/[\s]+/', $text);
            $activeChannels = $state['active_channels'] ?? [1, 2];

            $customDevices = Storage::loadRegisteredDevices();
            $key = isset($customDevices[(int) $serial]) ? (int) $serial : (isset($customDevices[$serial]) ? $serial : (int) $serial);
            if (!isset($customDevices[$key]['channels'])) {
                $customDevices[$key]['channels'] = [];
            }

            $summaryLines = [];
            if (count($activeChannels) === 1) {
                $ch = (string) $activeChannels[0];
                $num = $parts[0] ?? '';
                $customDevices[$key]['channels'][$ch]['meter_number'] = $num;
                $summaryLines[] = "• Канал {$ch}: № <b>{$num}</b>";
            } else {
                $num1 = $parts[0] ?? '';
                $num2 = $parts[1] ?? '';
                if ($num1 !== '') {
                    $customDevices[$key]['channels']['1']['meter_number'] = $num1;
                    $summaryLines[] = "• Канал 1: № <b>{$num1}</b>";
                }
                if ($num2 !== '') {
                    $customDevices[$key]['channels']['2']['meter_number'] = $num2;
                    $summaryLines[] = "• Канал 2: № <b>{$num2}</b>";
                }
            }

            Storage::saveRegisteredDevices($customDevices);
            Storage::clearUserState($chatId);
            $newMainKey = $this->telegram->buildMainReplyKeyboard($chatId);

            $addr = $customDevices[$key]['address'] ?? $customDevices[$key]['name'] ?? "Прибор № {$serial}";
            $summary = implode("\n", $summaryLines);

            Telegram::sendMessage(
                $chatId,
                "✅ <b>Номера счётчиков успешно сохранены!</b>\n\n📍 <b>{$addr}</b> (Прибор № {$serial})\n\n{$summary}\n\n<i>Выберите адрес в меню внизу для просмотра показаний.</i>",
                $token,
                $newMainKey
            );
            return;
        }

        // Редактирование начальных показаний
        if ($step === 'EDIT_INITIAL') {
            $cleanText = str_replace(',', '.', $text);
            $parts = preg_split('/[\s]+/', $cleanText);
            $activeChannels = $state['active_channels'] ?? [1, 2];

            $customDevices = Storage::loadRegisteredDevices();
            $key = isset($customDevices[(int) $serial]) ? (int) $serial : (isset($customDevices[$serial]) ? $serial : (int) $serial);
            if (!isset($customDevices[$key]['channels'])) {
                $customDevices[$key]['channels'] = [];
            }

            $summaryLines = [];
            if (count($activeChannels) === 1) {
                $ch = (string) $activeChannels[0];
                $val = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
                $valFormatted = number_format($val, 2, '.', '');

                $customDevices[$key]['channels'][$ch]['user_initial'] = $val;
                $customDevices[$key]['channels'][$ch]['base_api_value'] = null;
                $customDevices[$key]['initial_values'][$ch] = $val;
                $summaryLines[] = "• Вход {$ch}: <b>{$valFormatted} m³</b>";
            } else {
                $val1 = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
                $val2 = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : (isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0);
                $val1F = number_format($val1, 2, '.', '');
                $val2F = number_format($val2, 2, '.', '');

                $customDevices[$key]['channels']['1']['user_initial'] = $val1;
                $customDevices[$key]['channels']['1']['base_api_value'] = null;
                $customDevices[$key]['initial_values']['1'] = $val1;

                $customDevices[$key]['channels']['2']['user_initial'] = $val2;
                $customDevices[$key]['channels']['2']['base_api_value'] = null;
                $customDevices[$key]['initial_values']['2'] = $val2;

                $summaryLines[] = "• Вход 1: <b>{$val1F} m³</b>";
                $summaryLines[] = "• Вход 2: <b>{$val2F} m³</b>";
            }

            Storage::saveRegisteredDevices($customDevices);
            Storage::clearUserState($chatId);
            $newMainKey = $this->telegram->buildMainReplyKeyboard($chatId);

            $addr = $customDevices[$key]['address'] ?? $customDevices[$key]['name'] ?? "Прибор № {$serial}";
            $summary = implode("\n", $summaryLines);

            Telegram::sendMessage(
                $chatId,
                "✅ <b>Начальные показания успешно сохранены!</b>\n\n📍 <b>{$addr}</b> (Прибор № {$serial})\n\n{$summary}\n\n<i>Выберите адрес в меню внизу для просмотра показаний.</i>",
                $token,
                $newMainKey
            );
            return;
        }
    }
}
