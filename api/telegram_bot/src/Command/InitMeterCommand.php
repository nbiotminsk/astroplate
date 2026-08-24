<?php

declare(strict_types=1);

namespace TelegramBot\Command;

use TelegramBot\DTO\TelegramUpdateDTO;
use TelegramBot\MeterService;
use TelegramBot\Repository\DeviceRepositoryInterface;
use TelegramBot\Repository\MeterCacheRepositoryInterface;
use TelegramBot\Telegram;

class InitMeterCommand implements CommandInterface
{
    public function __construct(
        private Telegram $telegram,
        private MeterService $meterService,
        private DeviceRepositoryInterface $deviceRepo,
        private MeterCacheRepositoryInterface $cacheRepo
    ) {}

    public function supports(TelegramUpdateDTO $update): bool
    {
        return !$update->isCallbackQuery && (str_starts_with($update->text, '/init ') || str_starts_with($update->text, '/set '));
    }

    public function handle(TelegramUpdateDTO $update, array $config): void
    {
        $token = $config['telegram_token'];
        $chatId = $update->chatId;
        $text = $update->text;
        $mainKey = $this->telegram->buildMainReplyKeyboard($chatId);

        $parts = preg_split('/\s+/', trim($text));
        if (count($parts) >= 4) {
            $serial = $parts[1];
            $chNum = (int) $parts[2];
            $val = (float) str_replace(',', '.', $parts[3]);

            $customDevices = $this->deviceRepo->loadAll();
            if (!isset($customDevices[(int) $serial])) {
                $dev = $this->meterService->deviceLookup($config, $serial);
                if ($dev) {
                    $this->deviceRepo->registerDevice($serial, $dev->deviceId, $dev->name);
                    $customDevices = $this->deviceRepo->loadAll();
                }
            }

            if (isset($customDevices[(int) $serial])) {
                if (!isset($customDevices[(int) $serial]['initial_values'])) {
                    $customDevices[(int) $serial]['initial_values'] = [];
                }
                $customDevices[(int) $serial]['initial_values'][(string) $chNum] = $val;
                if (!isset($customDevices[(int) $serial]['channels'])) {
                    $customDevices[(int) $serial]['channels'] = [];
                }
                if (!isset($customDevices[(int) $serial]['channels'][(string) $chNum])) {
                    $customDevices[(int) $serial]['channels'][(string) $chNum] = [];
                }
                $customDevices[(int) $serial]['channels'][(string) $chNum]['user_initial'] = $val;
                $customDevices[(int) $serial]['channels'][(string) $chNum]['base_api_value'] = null;

                $this->deviceRepo->registerDevice(
                    $serial,
                    $customDevices[(int) $serial]['device_id'] ?? '',
                    $customDevices[(int) $serial]['name'] ?? "Устройство {$serial}",
                    $customDevices[(int) $serial]['initial_values'],
                    $customDevices[(int) $serial]['address'] ?? null,
                    $customDevices[(int) $serial]['active_channels'] ?? null,
                    $customDevices[(int) $serial]['channels']
                );

                $devId = $customDevices[(int) $serial]['device_id'] ?? '';
                $this->cacheRepo->clearChannelCache($devId, $chNum);

                $this->telegram->sendMessage($chatId, "✅ Начальное показание для прибора <code>{$serial}</code> (Канал {$chNum}) успешно установлено: <b>{$val} m³</b>.", $token, $mainKey);
            } else {
                $this->telegram->sendMessage($chatId, "❌ Прибор с серийным номером <code>{$serial}</code> не найден.", $token, $mainKey);
            }
        } else {
            $this->telegram->sendMessage($chatId, "Использование команды:\n<code>/init СЕРИЙНЫЙ_№ КАНАЛ ЗНАЧЕНИЕ</code>\n\nПример:\n<code>/init 8527038 1 0.12</code>\n<code>/init 8524390 1 0.06</code>", $token, $mainKey);
        }
    }
}
