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

            $customDevices = Storage::loadRegisteredDevices();
            if (isset($customDevices[(int) $serial])) {
                $customDevices[(int) $serial]['address'] = $text;
                $customDevices[(int) $serial]['name'] = $text;
                Storage::saveRegisteredDevices($customDevices);
            }

            $userMeters = Storage::loadUserMeters();
            if (isset($userMeters[$chatId][$serial])) {
                $userMeters[$chatId][$serial]['name'] = $text;
                $userMeters[$chatId][$serial]['address'] = $text;
                Storage::saveUserMeters($userMeters);
            }

            Storage::clearUserState($chatId);
            $newMainKey = $this->telegram->buildMainReplyKeyboard($chatId);

            $device = $this->meterService->deviceLookup($config, $serial);
            $report = $device ? $this->reportService->buildReport($config, $device) : "📍 {$text}";

            Telegram::sendMessage($chatId, "✅ <b>Адрес установки успешно изменён!</b>\n\n" . $report, $token, $newMainKey);
            return;
        }

        // Редактирование номеров счётчиков
        if ($step === 'EDIT_METERS') {
            $parts = preg_split('/[\s]+/', $text);
            $activeChannels = $state['active_channels'] ?? [1, 2];

            $customDevices = Storage::loadRegisteredDevices();
            if (!isset($customDevices[(int) $serial]['channels'])) {
                $customDevices[(int) $serial]['channels'] = [];
            }

            if (count($activeChannels) === 1) {
                $ch = (string) $activeChannels[0];
                $customDevices[(int) $serial]['channels'][$ch]['meter_number'] = $parts[0] ?? '';
            } else {
                if (isset($parts[0])) {
                    $customDevices[(int) $serial]['channels']['1']['meter_number'] = $parts[0];
                }
                if (isset($parts[1])) {
                    $customDevices[(int) $serial]['channels']['2']['meter_number'] = $parts[1];
                }
            }

            Storage::saveRegisteredDevices($customDevices);
            Storage::clearUserState($chatId);
            $newMainKey = $this->telegram->buildMainReplyKeyboard($chatId);

            $device = $this->meterService->deviceLookup($config, $serial);
            $report = $device ? $this->reportService->buildReport($config, $device) : "Номер счётчика обновлён.";

            Telegram::sendMessage($chatId, "✅ <b>Номера счётчиков успешно обновлены!</b>\n\n" . $report, $token, $newMainKey);
            return;
        }

        // Редактирование начальных показаний
        if ($step === 'EDIT_INITIAL') {
            $cleanText = str_replace(',', '.', $text);
            $parts = preg_split('/[\s]+/', $cleanText);
            $activeChannels = $state['active_channels'] ?? [1, 2];
            $uuid = (string) ($state['uuid'] ?? '');

            // Получаем текущие показания с API для фиксации точки отсчета base_api_value
            $readings = [];
            try {
                $info = UnicBoard::getDeviceInfo($config, $uuid);
                $readings = MeterService::extractCurrentReadingsFromDeviceInfo($info['payload'] ?? null);
            } catch (\Exception $e) {}

            $customDevices = Storage::loadRegisteredDevices();
            if (!isset($customDevices[(int) $serial]['channels'])) {
                $customDevices[(int) $serial]['channels'] = [];
            }

            if (count($activeChannels) === 1) {
                $ch = (string) $activeChannels[0];
                $val = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
                $base = isset($readings[(int) $ch]) && $readings[(int) $ch]->lastValue !== null ? (float) $readings[(int) $ch]->lastValue : 0.0;

                $customDevices[(int) $serial]['channels'][$ch]['user_initial'] = $val;
                $customDevices[(int) $serial]['channels'][$ch]['base_api_value'] = $base;
                $customDevices[(int) $serial]['initial_values'][$ch] = $val;
            } else {
                $val1 = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
                $val2 = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : (isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0);

                $base1 = isset($readings[1]) && $readings[1]->lastValue !== null ? (float) $readings[1]->lastValue : 0.0;
                $base2 = isset($readings[2]) && $readings[2]->lastValue !== null ? (float) $readings[2]->lastValue : 0.0;

                $customDevices[(int) $serial]['channels']['1']['user_initial'] = $val1;
                $customDevices[(int) $serial]['channels']['1']['base_api_value'] = $base1;
                $customDevices[(int) $serial]['initial_values']['1'] = $val1;

                $customDevices[(int) $serial]['channels']['2']['user_initial'] = $val2;
                $customDevices[(int) $serial]['channels']['2']['base_api_value'] = $base2;
                $customDevices[(int) $serial]['initial_values']['2'] = $val2;
            }

            Storage::saveRegisteredDevices($customDevices);
            Storage::clearUserState($chatId);
            $newMainKey = $this->telegram->buildMainReplyKeyboard($chatId);

            $device = $this->meterService->deviceLookup($config, $serial);
            $report = $device ? $this->reportService->buildReport($config, $device) : "Показания обновлены.";

            Telegram::sendMessage($chatId, "✅ <b>Начальные показания успешно обновлены!</b>\n\n" . $report, $token, $newMainKey);
            return;
        }
    }
}
