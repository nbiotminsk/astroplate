<?php

declare(strict_types=1);

namespace TelegramBot;

class BotHandler
{
    public static function handleUpdate(array $update, array $config): void
    {
        // Обработка Callback Query (нажатие инлайн-кнопок)
        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $cbId = $cb['id'];
            $chatId = (string) $cb['message']['chat']['id'];
            $token = $config['telegram_token'];
            $data = $cb['data'] ?? '';

            if (str_starts_with($data, 'month_')) {
                Telegram::answerCallbackQuery($cbId, $token);
                $serial = str_replace('month_', '', $data);
                $device = MeterService::deviceLookup($config, $serial);
                if ($device) {
                    $monthReport = ReportService::buildMonthReport($config, $device);
                    Telegram::sendMessage($chatId, $monthReport, $token);
                } else {
                    Telegram::sendMessage($chatId, "Устройство не найдено.", $token);
                }
                return;
            }

            if (str_starts_with($data, 'add_')) {
                $serial = str_replace('add_', '', $data);
                $device = MeterService::deviceLookup($config, $serial);
                if ($device) {
                    Storage::addUserMeter($chatId, $serial, $device['name']);
                    Telegram::answerCallbackQuery($cbId, $token, "Счетчик {$serial} добавлен!");
                    $replyKey = Telegram::buildMainReplyKeyboard($chatId);
                    Telegram::sendMessage($chatId, "✅ Счетчик <b>{$device['name']}</b> ({$serial}) добавлен в меню «📋 Мои счетчики».", $token, $replyKey);
                } else {
                    Telegram::answerCallbackQuery($cbId, $token, "Не удалось найти счетчик.");
                }
                return;
            }

            if (str_starts_with($data, 'del_')) {
                $serial = str_replace('del_', '', $data);
                Storage::removeUserMeter($chatId, $serial);
                Telegram::answerCallbackQuery($cbId, $token, "Счетчик {$serial} удален!");
                $replyKey = Telegram::buildMainReplyKeyboard($chatId);
                Telegram::sendMessage($chatId, "🗑 Счетчик <code>{$serial}</code> удален из ваших приборов.", $token, $replyKey);
                return;
            }
        }

        $message = $update['message'] ?? null;
        if (!$message || empty($message['text'])) {
            return;
        }

        $chatId = (string) $message['chat']['id'];
        $token = $config['telegram_token'];
        $text = trim($message['text']);
        $mainKey = Telegram::buildMainReplyKeyboard($chatId);

        if ($text === '/start' || $text === '/help') {
            Telegram::sendMessage($chatId, Telegram::TO_CMD, $token, $mainKey);
            return;
        }

        if ($text === '/my' || $text === '📋 Мои счетчики') {
            Telegram::sendMessage($chatId, ReportService::userMetersList($config, $chatId), $token, $mainKey);
            return;
        }

        if ($text === '➕ Добавить счетчик') {
            Telegram::sendMessage($chatId, "Чтобы зарегистрировать новый прибор в боте, отправьте команду:\n<code>/add ID UUID</code>\n\nПример:\n<code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f</code>", $token, $mainKey);
            return;
        }

        // Регистрация нового прибора через команду: /add ID UUID (например /add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f)
        if (str_starts_with($text, '/add ')) {
            $parts = preg_split('/\s+/', trim($text));
            // $parts[0] = '/add', $parts[1] = serial/id, $parts[2] = uuid
            if (count($parts) >= 3) {
                $serial = $parts[1];
                $uuid = $parts[2];
                $name = count($parts) >= 4 ? implode(' ', array_slice($parts, 3)) : "Счетчик {$serial}";

                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                    Telegram::sendMessage($chatId, "❌ Неверный формат UUID. Укажите корректный UUID прибора.", $token, $mainKey);
                    return;
                }

                Storage::registerCustomDevice($serial, $uuid, $name);
                MeterService::fetchAndSaveInitialValues($config, $serial, $uuid);
                Storage::addUserMeter($chatId, $serial, $name);
                $newKey = Telegram::buildMainReplyKeyboard($chatId);

                Telegram::sendMessage($chatId, "🎉 Новый прибор успешно зарегистрирован!\n\n• <b>ID / Серийный №</b>: <code>{$serial}</code>\n• <b>UUID</b>: <code>{$uuid}</code>\n\nОн также автоматически сохранен в ваше меню «📋 Мои счетчики».", $token, $newKey);
            } else {
                Telegram::sendMessage($chatId, "Неверный формат команды.\n\nИспользование:\n<code>/add ID UUID</code>\n\nПример:\n<code>/add 8527038 2e50bc92-6c87-4b64-b22e-e96e7997476f</code>", $token, $mainKey);
            }
            return;
        }

        if (str_starts_with($text, '/del ')) {
            $serial = trim(substr($text, 5));
            Storage::removeUserMeter($chatId, $serial);
            $newKey = Telegram::buildMainReplyKeyboard($chatId);
            Telegram::sendMessage($chatId, "🗑 Счетчик <code>{$serial}</code> удален из списка ваших сохраненных приборов.", $token, $newKey);
            return;
        }

        // Задать/изменить начальные показания: /init СЕРИЙНЫЙ_№ КАНАЛ ЗНАЧЕНИЕ (например /init 8527038 1 0.12)
        if (str_starts_with($text, '/init ') || str_starts_with($text, '/set ')) {
            $parts = preg_split('/\s+/', trim($text));
            if (count($parts) >= 4) {
                $serial = $parts[1];
                $chNum = (int) $parts[2];
                $val = (float) str_replace(',', '.', $parts[3]);

                $customDevices = Storage::loadRegisteredDevices();
                if (!isset($customDevices[(int) $serial])) {
                    $dev = MeterService::deviceLookup($config, $serial);
                    if ($dev) {
                        Storage::registerCustomDevice($serial, $dev['device_id'], $dev['name']);
                        $customDevices = Storage::loadRegisteredDevices();
                    }
                }

                if (isset($customDevices[(int) $serial])) {
                    if (!isset($customDevices[(int) $serial]['initial_values'])) {
                        $customDevices[(int) $serial]['initial_values'] = [];
                    }
                    $customDevices[(int) $serial]['initial_values'][(string) $chNum] = $val;
                    Storage::saveRegisteredDevices($customDevices);

                    // Очищаем локальный кэш расхода, чтобы пересчитать с новым начальным значением
                    $cache = Storage::loadMeterCache();
                    $devId = $customDevices[(int) $serial]['device_id'] ?? '';
                    if ($devId && isset($cache[$devId]['channels'][$chNum])) {
                        unset($cache[$devId]['channels'][$chNum]);
                        Storage::saveMeterCache($cache);
                    }

                    Telegram::sendMessage($chatId, "✅ Начальное показание для прибора <code>{$serial}</code> (Канал {$chNum}) успешно установлено: <b>{$val} m³</b>.", $token, $mainKey);
                } else {
                    Telegram::sendMessage($chatId, "❌ Прибор с серийным номером <code>{$serial}</code> не найден.", $token, $mainKey);
                }
            } else {
                Telegram::sendMessage($chatId, "Использование команды:\n<code>/init СЕРИЙНЫЙ_№ КАНАЛ ЗНАЧЕНИЕ</code>\n\nПример:\n<code>/init 8527038 1 0.12</code>\n<code>/init 8524390 1 0.06</code>", $token, $mainKey);
            }
            return;
        }

        // Клик по кнопке постоянной клавиатуры типа "💧 Fluo (8527038)"
        if (preg_match('/\((\d+)\)$/', $text, $matches)) {
            $text = $matches[1];
        }

        $device = MeterService::deviceLookup($config, $text);
        if (!$device) {
            Telegram::sendMessage($chatId, "Устройство не найдено.\n\n" . Telegram::TO_CMD, $token, $mainKey);
            return;
        }

        $serial = $device['serial_number'] ?? $text;
        $userMeters = Storage::getUserMeters($chatId);
        $isAdded = isset($userMeters[$serial]);

        $report = ReportService::buildReport($config, $device);
        $keyboard = Telegram::buildDeviceKeyboard($serial, $isAdded);

        Telegram::sendMessage($chatId, $report, $token, $keyboard);
    }

    public static function checkConfig(array $config): void
    {
        $missing = [];
        if (($config['telegram_token'] ?? '') === '') {
            $missing[] = 'TELEGRAM_BOT_TOKEN';
        }
        if (($config['unicboard_token'] ?? '') === '') {
            $missing[] = 'UNICBOARD_API_TOKEN';
        }
        if ($missing) {
            fwrite(STDERR, "Ошибка: задайте в .env: " . implode(', ', $missing) . "\n");
            exit(1);
        }
    }

    /** Режим Webhook. Запуск на веб-сервере. */
    public static function runWebhook(array $config): void
    {
        self::checkConfig($config);

        $secret = $config['webhook_secret'] ?? '';
        if ($secret !== '' && ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== $secret) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $raw = file_get_contents('php://input');
        $update = json_decode((string) $raw, true);
        if (is_array($update)) {
            self::handleUpdate($update, $config);
        }
        http_response_code(200);
        echo 'ok';
    }

    /** Режим Long-polling. Запуск из CLI. */
    public static function runPolling(array $config): void
    {
        self::checkConfig($config);
        $offset = 0;
        echo "Бот запущен (long-polling). Нажмите Ctrl+C для остановки.\n";

        while (true) {
            $url = "https://api.telegram.org/bot{$config['telegram_token']}/getUpdates?timeout=50&offset={$offset}";
            [$code, $resp] = Telegram::httpGet($url, [], 60, 10);
            if ($code !== 200 || !($resp['ok'] ?? false)) {
                sleep(2);
                continue;
            }

            foreach ($resp['result'] ?? [] as $update) {
                $updateId = $update['update_id'] ?? null;
                if ($updateId !== null && $updateId >= $offset) {
                    $offset = $updateId + 1;
                }
                self::handleUpdate($update, $config);
            }
        }
    }
}
