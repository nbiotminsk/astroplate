<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\Storage;
use TelegramBot\Telegram;

class InitMeterCommand implements CommandInterface
{
    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && (str_starts_with($update->text, '/init ') || str_starts_with($update->text, '/set '));
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $text = $update->text;
        $mainKey = Telegram::buildMainReplyKeyboard($chatId);

        $parts = preg_split('/\s+/', trim($text));
        if (count($parts) >= 4) {
            $serial = $parts[1];
            $chNum = (int) $parts[2];
            $val = (float) str_replace(',', '.', $parts[3]);

            $customDevices = Storage::loadRegisteredDevices();
            if (!isset($customDevices[(int) $serial])) {
                $dev = MeterService::deviceLookup($config, $serial);
                if ($dev) {
                    Storage::registerCustomDevice($serial, $dev->deviceId, $dev->name);
                    $customDevices = Storage::loadRegisteredDevices();
                }
            }

            if (isset($customDevices[(int) $serial])) {
                if (!isset($customDevices[(int) $serial]['initial_values'])) {
                    $customDevices[(int) $serial]['initial_values'] = [];
                }
                $customDevices[(int) $serial]['initial_values'][(string) $chNum] = $val;
                Storage::saveRegisteredDevices($customDevices);

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
    }
}
