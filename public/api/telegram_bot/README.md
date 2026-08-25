# 📖 Архитектура и справочник PHP-файлов Telegram-бота UnicBoard

Комплексная система мониторинга приборов учёта воды и тепла компании «Телеофис» (teleofis24.by). Бот интегрирован с платформой **UnicBoard (DataUnic)**, поддерживает импульсные модемы (Юпитер / MM219, Вега, Arvas, RTU) и ультразвуковые счётчики **Fluo**, работает с СУБД **MariaDB 11.4** через PDO и имеет 100% покрытие модульными тестами.

---

## 📊 Общая сводка по кодовой базе PHP

* **Всего PHP-файлов:** `54`
* **Всего строк кода:** `7 574`
* **Всего классов:** `110` (включая DTO, сервисы, репозитории, команды и тестовые сценарии)
* **Всего интерфейсов:** `4`
* **Всего методов и функций:** `234`
* **Используемые PHP-расширения и библиотеки:** `ext-pdo`, `ext-pdo_mysql`, `ext-curl`, `ext-json`, `ext-mbstring`, `ext-pcre`, `ext-spl`.
* **Тестовый набор:** `195 / 195` тестов успешно пройдены (`php tests/run_tests.php`).

---

## 🗺 Структура каталогов

```text
public/api/telegram_bot/
├── bot.php                         # Точка входа Telegram Webhook
├── ping.php                        # Healthcheck, ping и фоновая автосинхронизация
├── config.php                      # Конфигурационный массив окружения
├── env.php                         # Безопасный загрузчик .env
├── scripts/                        # Служебные CLI-скрипты и миграции
│   ├── debug_info.php              # Диагностика и дамп UnicBoard API
│   └── migrate_json_to_db.php      # Миграция JSON-хранилища в MariaDB
├── src/                            # Исходный код ядра бота (PSR-4: TelegramBot\)
│   ├── BotHandler.php              # Главный контроллер обработки Webhook
│   ├── Container.php               # Контейнер внедрения зависимостей (DI)
│   ├── Database.php                # Менеджер подключения к MariaDB и автомиграции
│   ├── KeyboardBuilder.php         # Фабрика кнопок и меню Telegram
│   ├── MeterService.php            # Расчёт показаний, дельт и фильтрация приборов
│   ├── ReportService.php           # Генератор отчётов, графиков и карточек приборов
│   ├── Storage.php                 # Файловый кэш, состояния сессий и логирование
│   ├── Telegram.php                # HTTP-клиент Telegram Bot API (cURL)
│   ├── UnicBoard.php               # Отказоустойчивый клиент UnicBoard REST API
│   ├── DTO/                        # Data Transfer Objects (строгая типизация)
│   ├── Command/                    # Команды и callback-обработчики (GoF Command)
│   ├── Repository/                 # Слой работы с БД и файловым хранилищем
│   └── Exception/                  # Пользовательские исключения
└── tests/                          # Модульные и интеграционные тесты
    ├── run_tests.php               # Главный раннер тестов
    ├── TestRunner.php              # Тестовый микро-фреймворк
    └── *Test.php                   # Тестовые наборы
```

---

## 📂 Подробное описание всех 54 PHP-файлов

---

### 1. Точки входа и конфигурация (Root & Entry Points)

#### `bot.php`
* **Назначение:** Главная точка входа для входящих Webhook-запросов от серверов Telegram.
* **Используемые модули / библиотеки:** `ext-json`, `TelegramBot\Container`, `TelegramBot\BotHandler`, `TelegramBot\Storage`.
* **Функционал:**
  * Получает тело HTTP POST запроса (`php://input`).
  * Инициализирует DI-контейнер `Container`.
  * Передаёт `update` в `BotHandler->handle()`.
  * Логирует необработанные исключения и отдаёт Telegram `HTTP 200 OK`.

#### `ping.php`
* **Назначение:** Эндпоинт мониторинга (healthcheck), проверка доступности БД/API и запуск автосинхронизации показаний по cron.
* **Используемые модули / библиотеки:** `ext-curl`, `ext-pdo`, `TelegramBot\UnicBoard`, `TelegramBot\Database`, `TelegramBot\ReadingRepository`.
* **Функционал:**
  * Проверяет доступность UnicBoard API (`UnicBoard::ping()`) с замером времени ответа в мс.
  * Проверяет подключение к MariaDB (`Database::getConnection()`).
  * Опрашивает зарегистрированные приборы и записывает свежие снимки в `device_info_snapshots` и `device_readings_log`.

#### `config.php`
* **Назначение:** Основной конфигурационный файл. Загружает параметры окружения из `.env` и определяет настройки по умолчанию.
* **Используемые модули / библиотеки:** `env.php`.
* **Возвращает:** Массив конфигурации: `telegram_token`, `unicboard_api_base`, `unicboard_api_token`, `database` (host, port, user, pass, dbname), `timezone`, `devices`.

#### `env.php`
* **Назначение:** Парсер файлов `.env` без сторонних тяжёлых зависимостей.
* **Функции:**
  * `env(string $key, mixed $default = null): mixed` — извлекает значение из `$_ENV`, `$_SERVER` или системного окружения `getenv()`.
  * Загружает и парсит `.env` и `.env.local` при старте скрипта.

---

### 2. Служебные скрипты и миграции (`scripts/`)

#### `scripts/migrate_json_to_db.php`
* **Назначение:** CLI-скрипт миграции существующих данных из JSON-файлов (`storage/*.json`) в реляционные таблицы MariaDB.
* **Классы и методы:**
  * Использует `TelegramBot\Database`, `TelegramBot\Storage`, `PDO`.
* **Функционал:**
  * Переносит приборы из `registered_devices.json` в таблицу `devices`.
  * Переносит привязки пользователей из `user_meters.json` в таблицу `user_devices`.
  * Переносит кэш показаний из `meter_cache.json` в таблицу `meter_cache`.
  * Очищает битые/пустые серийные номера и исключает дублирование.

#### `scripts/debug_info.php`
* **Назначение:** CLI-утилита для разработчика. Делает прямой тестовый запрос к UnicBoard API по серийному номеру или UUID и выводит структурированный дамп ответа.
* **Классы и методы:**
  * Использует `TelegramBot\UnicBoard`, `TelegramBot\MeterService`.

---

### 3. Ядро приложения и инфраструктура (`src/`)

#### `src/BotHandler.php`
* **Класс:** `TelegramBot\BotHandler`
* **Назначение:** Преобразует сырой JSON-пейлоад от Telegram в строго типизированный `TelegramUpdateDTO` и передаёт его в диспетчер команд.
* **Методы:**
  * `__construct(CommandDispatcher $dispatcher, ?Telegram $telegram = null)`
  * `handle(array $updateData, array $config): void` — точка входа диспетчеризации.

#### `src/Container.php`
* **Класс:** `TelegramBot\Container`
* **Назначение:** Легковесный Dependency Injection (DI) контейнер с ленивой инициализацией сервисов.
* **Методы:**
  * `__construct(array $config)`
  * `set(string $id, callable $factory): void` — регистрация фабрики сервиса.
  * `get(string $id): mixed` — получение или создание синглтон-экземпляра.
  * `registerServices(): void` — биндинг репозиториев (SQL), сервисов (`MeterService`, `ReportService`, `KeyboardBuilder`), клиентов (`Telegram`) и цепочки команд.

#### `src/Database.php`
* **Класс:** `TelegramBot\Database`
* **Назначение:** Менеджер подключения к базе данных MariaDB 11.4 через PDO и автомиграции структуры таблиц.
* **Методы:**
  * `getConnection(array $config = []): ?PDO` — синглтон-подключение к БД.
  * `autoMigrate(PDO $pdo): void` — автоматическое создание и проверка индексов таблиц `devices`, `user_devices`, `meter_cache`, `device_readings_log`, `device_info_snapshots`.
  * `resetForTests(): void` — сброс состояния для unit-тестов.

#### `src/KeyboardBuilder.php`
* **Класс:** `TelegramBot\KeyboardBuilder`
* **Назначение:** Фабрика генерации интерфейса пользователя (Reply и Inline клавиатур Telegram).
* **Методы:**
  * `buildMainReplyKeyboard(string $chatId, string $prefix = '📍 '): array` — нижняя клавиатура со списком объектов пользователя.
  * `buildCancelReplyKeyboard(): array` — кнопка отмены `❌ Отмена`.
  * `buildDeviceKeyboard(string $serialOrId, bool $isAdded = false): array` — 3-рядное меню карточки прибора (Опрос, Архив, Диагностика 50%, Изменить 50%, Удалить).
  * `buildDiagnosticKeyboard(string $serialOrId): array` — главное меню диагностики.
  * `buildDiagSubKeyboard(string $serialOrId): array` — подменю возврата к диагностике.
  * `buildEditDeviceKeyboard(string $serialOrId, bool $isFluo = false): array` — меню редактирования параметров.
  * `buildEditChannelChoiceKeyboard(string $serialOrId): array` — выбор активных входов при редактировании.
  * `buildChannelChoiceInlineKeyboard(): array` — выбор входов в мастере добавления.
  * `buildSkipInitInlineKeyboard(int|string $channel): array` — кнопка `⏭️ Пропустить ввод показаний`.

#### `src/Telegram.php`
* **Класс:** `TelegramBot\Telegram`
* **Назначение:** Сетевой транспортный клиент Telegram Bot API на базе cURL с сокрытием токенов в логах.
* **Методы:**
  * `sendMessage(string $chatId, string $text, string $token, ?array $replyMarkup = null): void`
  * `answerCallbackQuery(string $callbackQueryId, string $token, string $text = ''): void`
  * `tgApi(string $method, array $params, string $token): array` — вызов произвольного метода API Telegram.
  * `httpGet(string $url, ...): array` / `httpPostJson(string $url, ...): array` — выполнение HTTP-запросов.
  * `redactUrlForLog(string $url): string` — безопасное маскирование bot-токена в логах.
  * Делегирует построение клавиатур в `KeyboardBuilder` для сохранения обратной совместимости.

#### `src/UnicBoard.php`
* **Класс:** `TelegramBot\UnicBoard`
* **Назначение:** Отказоустойчивый клиент к REST API платформы UnicBoard с экспоненциальными повторами и чередованием эндпоинтов.
* **Методы:**
  * `getDeviceInfo(array $config, string $deviceId, int $maxRetries = 4): array` — получение текущих данных прибора по UUID.
  * `getAllDevices(array $config, int $limit = 100): array` — список всех доступных модемов.
  * `getDeviceValues(array $config, string $deviceId, int $limit = 50, ...): array` — архив показаний `/devices/values`.
  * `getBatteryLevel(array $config, string $deviceId): array` — заряд батареи в вольтах.
  * `getTemperatures(array $config, string $deviceId): array` — температура прибора в °C.
  * `getDeviceEvents(array $config, string $deviceId): array` — журнал аварий и событий.
  * `ping(array $config): array` — проверка соединения с UnicBoard.

#### `src/MeterService.php`
* **Класс:** `TelegramBot\MeterService`
* **Назначение:** Бизнес-логика расчёта показаний, парсинга сырых данных модема, нормализации коэффициентов и поиска приборов.
* **Методы:**
  * `deviceLookup(array $config, string $input, ?string $chatId = null): ?DeviceDTO` — интеллектуальный поиск прибора по номеру/адресу с приоритизацией имени пользователя.
  * `extractCurrentReadingsFromDeviceInfo(?array $payload): array` — извлечение показаний каналов из `/info`.
  * `extractHistoricalRecordsFromValues(array $payload): array` — извлечение физических записей из архива.
  * `isFluoDevice(?array $infoPayload, ?DeviceDTO $device = null): bool` — проверка, является ли прибор ультразвуковым счётчиком Fluo.
  * `calculateAdjustedReading(float $currentApiValue, ?float $userInitial, ?float $baseApiValue): float` — формула корректировки циферблата: `UserInitial + (Current - Base)`.
  * `parseUtcTimestamp(string $dateStr): int` — корректный парсинг дат UnicBoard в таймзону Минска.

#### `src/ReportService.php`
* **Класс:** `TelegramBot\ReportService`
* **Назначение:** Формирование форматированных HTML-отчётов для Telegram (карточка прибора, месячный архив, диагностические срезы).
* **Методы:**
  * `buildReport(array $config, DeviceDTO $device): string` — мгновенная сводная карточка прибора.
  * `buildMonthReport(array $config, DeviceDTO $device): string` — отчёт расхода за текущий календарный месяц.
  * `buildDiagnosticReport(array $config, DeviceDTO $device): string` — сводная диагностика.
  * `buildDiagChannelsReport(array $config, DeviceDTO $device): string` — импульсы и вес импульса по каналам.
  * `buildDiagBatteryReport(array $config, DeviceDTO $device): string` — статус питания и батареи.
  * `buildDiagTemperatureReport(array $config, DeviceDTO $device): string` — температура модема.
  * `buildDiagClockReport(array $config, DeviceDTO $device): string` — часы модема и серверное время.

#### `src/Storage.php`
* **Класс:** `TelegramBot\Storage`
* **Назначение:** Управление временными состояниями пользователей (FSM в мастере добавления/редактирования), файловым кэшем и логами.
* **Методы:**
  * `getUserState(string $chatId): ?array` / `setUserState(...)` / `clearUserState(...)`
  * `log(string $message): void` — запись в `storage/bot.log`.

#### `src/Exception/ApiUnavailableException.php`
* **Класс:** `TelegramBot\Exception\ApiUnavailableException` (extends `\RuntimeException`)
* **Назначение:** Выбрасывается при полном отказе внешнего API UnicBoard для показа дружелюбного экрана с кнопкой «🔄 Попробовать снова».

---

### 4. Объекты передачи данных (`src/DTO/`)

#### `src/DTO/TelegramUpdateDTO.php`
* **Класс:** `TelegramBot\DTO\TelegramUpdateDTO` (readonly)
* **Свойства:** `updateId`, `chatId`, `text`, `isCallbackQuery`, `callbackData`, `callbackQueryId`, `messageId`.
* **Методы:** `fromArray(array $update): self`.

#### `src/DTO/DeviceDTO.php`
* **Класс:** `TelegramBot\DTO\DeviceDTO` (readonly)
* **Свойства:** `deviceId` (UUID), `serialNumber`, `name`, `initialValues`, `address`, `activeChannels`, `channels`.
* **Методы:** `fromArray(array $data, string $id): self`, `toArray(): array`.

#### `src/DTO/ChannelReadingDTO.php`
* **Класс:** `TelegramBot\DTO\ChannelReadingDTO` (readonly)
* **Свойства:** `channelNumber`, `lastValue`, `lastValueDate`, `unitMultiplier`, `valueMultiplier`, `meterSerialNumber`, `batteryLevel`, `temperature`.
* **Методы:** `hasReading(): bool`.

#### `src/DTO/HistoricalValueDTO.php`
* **Класс:** `TelegramBot\DTO\HistoricalValueDTO` (readonly)
* **Свойства:** `channelNumber`, `value`, `date`, `valueType` (`DEVICE_DATA` / `INTERPOLATED_LINEAR`).
* **Методы:** `isPhysical(): bool`.

#### `src/DTO/MeterReadingDTO.php`
* **Класс:** `TelegramBot\DTO\MeterReadingDTO` (readonly)
* **Свойства:** `channelNumber`, `currentReading`, `consumptionMonth`, `unit`, `lastUpdateDate`.

---

### 5. Команды и обработчики (`src/Command/`)

#### `src/Command/CommandInterface.php`
* **Интерфейс:** `TelegramBot\Command\CommandInterface`
* **Методы:**
  * `supports(TelegramUpdateDTO $update): bool`
  * `handle(TelegramUpdateDTO $update, array $config): void`

#### `src/Command/CommandDispatcher.php`
* **Класс:** `TelegramBot\Command\CommandDispatcher`
* **Назначение:** Реализует паттерн Chain of Responsibility — перебирает зарегистрированные команды и передаёт управление первой подходящей.
* **Методы:** `dispatch(TelegramUpdateDTO $update, array $config): void`.

#### `src/Command/StartCommand.php`
* **Класс:** `TelegramBot\Command\StartCommand`
* **Триггер:** `/start`, `/help`
* **Действие:** Отправляет главное приветствие `Telegram::TO_CMD` и отрисовывает нижнюю клавиатуру.

#### `src/Command/MyMetersCommand.php`
* **Класс:** `TelegramBot\Command\MyMetersCommand`
* **Триггер:** `/my`, `📋 Мои счетчики`
* **Действие:** Выводит список всех добавленных приборов пользователя с текущими показаниями.

#### `src/Command/MeterDetailCommand.php`
* **Класс:** `TelegramBot\Command\MeterDetailCommand`
* **Триггер:** Клик по кнопке объекта (напр. `📍 Nero`) или ввод номера прибора.
* **Действие:** Вызывает `ReportService->buildReport()` и отправляет карточку прибора с кнопками управления.

#### `src/Command/AddDeviceCommand.php`
* **Класс:** `TelegramBot\Command\AddDeviceCommand`
* **Триггер:** `/add`, `➕ Добавить счетчик`, а также шаги FSM `WAITING_*`.
* **Действие:** Пошаговый мастер:
  1. Поиск модема в UnicBoard по номеру.
  2. Ввод адреса (для Fluo сразу завершается).
  3. Выбор каналов (1, 2 или оба).
  4. Раздельный ввод номера счётчика и показаний циферблата с возможностью пропуска (`⏭️ Пропустить`).

#### `src/Command/DelDeviceCommand.php`
* **Класс:** `TelegramBot\Command\DelDeviceCommand`
* **Триггер:** `/del <номер>`
* **Действие:** Удаляет прибор из списка пользователя и обновляет клавиатуру.

#### `src/Command/EditDeviceCommand.php`
* **Класс:** `TelegramBot\Command\EditDeviceCommand`
* **Триггер:** Callback `edit_*`, `set_ch_*`, `back_dev_*` и шаги FSM `EDIT_*`.
* **Действие:** Интерактивное изменение адреса, номеров счётчиков, начальных показаний и активных входов.

#### `src/Command/InitMeterCommand.php`
* **Класс:** `TelegramBot\Command\InitMeterCommand`
* **Триггер:** `/init <serial> <ch> <value>`
* **Действие:** Экспресс-установка начальных показаний циферблата в обход мастера.

#### `src/Command/PingServerCommand.php`
* **Класс:** `TelegramBot\Command\PingServerCommand`
* **Триггер:** `/ping`, `⚡ Тест сервера`
* **Действие:** Мгновенный тест связи с UnicBoard API и базой данных MariaDB с замером времени ответа.

#### `src/Command/AddMeterCallback.php`
* **Класс:** `TelegramBot\Command\AddMeterCallback`
* **Триггер:** Callback `add_<serial>`
* **Действие:** Добавление чужого/просматриваемого прибора в свой список.

#### `src/Command/DelMeterCallback.php`
* **Класс:** `TelegramBot\Command\DelMeterCallback`
* **Триггер:** Callback `del_<serial>`
* **Действие:** Удаление прибора через inline-кнопку карточки.

#### `src/Command/MonthArchiveCallback.php`
* **Класс:** `TelegramBot\Command\MonthArchiveCallback`
* **Триггер:** Callback `month_<serial>`
* **Действие:** Формирование и отправка отчёта расхода воды за календарный месяц.

#### `src/Command/DiagnosticCallback.php`
* **Класс:** `TelegramBot\Command\DiagnosticCallback`
* **Триггер:** Callback `diag_<serial>`, `diag_ch_*`, `diag_bat_*`, `diag_temp_*`, `diag_clock_*`
* **Действие:** Открытие экранов технической диагностики прибора.

---

### 6. Слой репозиториев (`src/Repository/`)

#### `src/Repository/DeviceRepositoryInterface.php`
* **Интерфейс:** Контракт реестра зарегистрированных в системе устройств (`registerDevice`, `findBySerialOrName`, `loadAll`, `removeDevice`).

#### `src/Repository/SqlDeviceRepository.php`
* **Класс:** Реализация `DeviceRepositoryInterface` для СУБД MariaDB (таблица `devices`). Сохраняет JSON-конфигурации каналов и UUID.

#### `src/Repository/JsonDeviceRepository.php`
* **Класс:** Файловая реализация `DeviceRepositoryInterface` (`storage/registered_devices.json`) для работы без БД.

#### `src/Repository/UserMeterRepositoryInterface.php`
* **Интерфейс:** Контракт персональных привязок приборов к Telegram Chat ID (`getMetersByChatId`, `addMeter`, `removeMeter`, `renameMeter`).

#### `src/Repository/SqlUserMeterRepository.php`
* **Класс:** Реализация `UserMeterRepositoryInterface` для MariaDB (таблица `user_devices`). Поддерживает персональные пользовательские названия объектов.

#### `src/Repository/JsonUserMeterRepository.php`
* **Класс:** Файловая реализация `UserMeterRepositoryInterface` (`storage/user_meters.json`).

#### `src/Repository/MeterCacheRepositoryInterface.php`
* **Интерфейс:** Контракт быстрого кэша последних известных показаний (`get`, `set`, `getAll`).

#### `src/Repository/SqlMeterCacheRepository.php`
* **Класс:** Реализация `MeterCacheRepositoryInterface` для MariaDB (таблица `meter_cache`).

#### `src/Repository/JsonMeterCacheRepository.php`
* **Класс:** Файловая реализация кэша в JSON (`storage/meter_cache.json`).

#### `src/Repository/ReadingRepository.php`
* **Класс:** Репозиторий исторических данных. Сохраняет горячие снимки `/info` (`device_info_snapshots`) и логи показаний (`device_readings_log`) для быстрой отдачи без повторных запросов в API.

---

### 7. Тестовый фреймворк и тестовые наборы (`tests/`)

#### `tests/run_tests.php`
* **Назначение:** Главный исполняемый файл для запуска полного прогона тестов (`php tests/run_tests.php`). Подключает все тест-сьюты и выводит итоговую статистику.

#### `tests/TestRunner.php`
* **Класс:** `TelegramBot\Tests\TestRunner`
* **Назначение:** Легковесный тестовый раннер с цветным терминальным выводом.
* **Методы:** `describe($section)`, `assert($condition, $message)`, `assertEquals($expected, $actual, $message)`.

#### `tests/CommandTest.php`
* **Класс:** `TelegramBot\Tests\CommandTest`
* **Назначение:** Тестирование всех команд бота (`/start`, `/my`, `/add`, `/del`, `/init`, `/ping`), пошагового мастера с пропуском показаний, кнопок и редактирования.

#### `tests/ContainerTest.php`
* **Класс:** `TelegramBot\Tests\ContainerTest`
* **Назначение:** Тестирование DI-контейнера `Container`, фабрик и синглтон-разрешения сервисов.

#### `tests/DTOTest.php`
* **Класс:** `TelegramBot\Tests\DTOTest`
* **Назначение:** Тестирование сериализации/десериализации и иммутабельности DTO (`DeviceDTO`, `ChannelReadingDTO`, `HistoricalValueDTO` и др.).

#### `tests/EdgeCasesTest.php`
* **Класс:** `TelegramBot\Tests\EdgeCasesTest`
* **Назначение:** Тестирование граничных условий, изоляции данных разных пользователей, обработки неполных JSON и специальных символов.

#### `tests/RepositoryTest.php`
* **Класс:** `TelegramBot\Tests\RepositoryTest`
* **Назначение:** Тестирование репозиториев MariaDB (SQL) и файловых JSON-репозиториев.

#### `tests/UnicBoardApiTest.php`
* **Класс:** `TelegramBot\Tests\UnicBoardApiTest`
* **Назначение:** Тестирование сценариев UnicBoard API (Tests A — Z): ретраи, экспоненциальные паузы, валидация по контракту OpenAPI, обработка ошибок 401/404/500/таймаутов.

#### `tests/test_device_report.php`
* **Назначение:** Интеграционный тест формирования отчётов по реальным моделям приборов (MM219, Fluo).

---

## 🛠 Запуск и обслуживание

### Проверка синтаксиса всех файлов:
```bash
for f in $(find public/api/telegram_bot -name "*.php"); do php -l "$f" || exit 1; done
```

### Запуск полного набора тестов:
```bash
php public/api/telegram_bot/tests/run_tests.php
```

### Проверка работоспособности на сервере:
```bash
curl -i https://teleofis24.by/api/telegram_bot/ping.php
```
