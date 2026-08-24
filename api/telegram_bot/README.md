# UnicBoard API & Telegram Bot Integration

Интеграция Telegram-бота с API платформы **UnicBoard (DataUnic)** для дистанционного мониторинга приборов учёта воды и тепла (модемы и счётчики **Юпитер / MM219**, **Fluo** и др.).

Проект основан на официальной спецификации [OpenAPI 3.0 (api.json)](file:///Users/nikolaj/Projects/astroplate/public/api/telegram_bot/api.json) и реализует отказоустойчивый клиент с многоуровневыми повторными попытками, структурированным JSON-логированием и разделением ответственности между слоями транспорта, парсинга данных и бизнес-логики.

---

## 🏗 Архитектура проекта

Кодовая база спроектирована по модульному принципу:

```
public/api/telegram_bot/
├── api.json                  # Спецификация OpenAPI 3.0 для UnicBoard API
├── bot.php                   # Точка входа для запуска бота в режиме Long Polling
├── config.php                # Загрузка и валидация конфигурации (.env)
├── test_jupiter_api.py       # Автономный Python-скрипт для быстрой проверки API
├── src/
│   ├── UnicBoard.php         # Отказоустойчивый клиент UnicBoard API (HTTP, ретраи, fallback, логирование)
│   ├── Telegram.php          # Клиент Telegram Bot API (HTTP cURL, отправка сообщений, вебхуки/polling)
│   ├── MeterService.php      # Извлечение и валидация показаний, расчёт коэффициентов, анализ расхода
│   ├── ReportService.php     # Формирование отчётов (текущие показания, месячный архив, fallback на /values)
│   ├── BotHandler.php        # Маршрутизация команд и обработка Callback-кнопок
│   ├── Container.php         # Простой Dependency Injection контейнер
│   ├── Storage.php           # JSON-хранилище данных пользователей и приборов
│   ├── Command/              # Обработчики команд бота (/start, /help, /add, /list, /report, /archive, /delete)
│   ├── DTO/                  # Строго типизированные DTO (DeviceDTO, ChannelReadingDTO, HistoricalValueDTO и др.)
│   └── Repository/           # Репозиторий приборов (UserMeterRepositoryInterface, FileUserMeterRepository)
└── tests/                    # Комплексный набор модульных и интеграционных тестов
    ├── run_tests.php         # Главный тест-раннер
    ├── UnicBoardApiTest.php  # Тесты сценариев API (ретраи, fallback, валидация полей, нормализация метаданных)
    ├── DTOTest.php           # Тесты DTO и преобразования данных
    ├── EdgeCasesTest.php     # Тесты граничных случаев
    ├── CommandTest.php       # Тесты команд бота
    ├── ContainerTest.php     # Тесты DI-контейнера
    └── RepositoryTest.php    # Тесты хранилища
```

---

## 📡 Эндпоинты UnicBoard API (`/api/v1`)

Базовый URL: `https://api.public.data-aggregator.unicboard.by`

Все закрытые запросы требуют заголовок авторизации:
```http
Authorization: Bearer <UNICBOARD_API_TOKEN>
Accept: application/json
```

### 1. Детальная информация по конкретному устройству
- **Метод**: `GET /api/v1/devices/{device_id}/info`
- **Path-параметры**: `device_id` (UUID)
- **Назначение**: Первичный источник текущих показаний (`device_meter.last_value`), даты (`last_value_date`), коэффициентов (`unit_multiplier`, `value_multiplier`) и статуса каналов.

### 2. Информация обо всех устройствах пользователя (Fallback)
- **Методы**: 
  - `POST /api/v1/devices/info` с телом `{"device_ids": ["<uuid>"]}` *(точечный fallback)*
  - `GET /api/v1/devices/info?limit=100` *(пакетный fallback со списком)*
- **Назначение**: Используется клиентом `UnicBoard::getDeviceInfo()` как многоуровневый fallback при недоступности прямого эндпоинта `{id}/info`.

### 3. Архив и журнал показаний
- **Метод**: `POST /api/v1/devices/values`
- **Тело запроса**: `{"devices_id": ["<uuid>"]}` *(обратите внимание: имя поля в теле запроса строго `devices_id` согласно OpenAPI)*
- **Query-параметры**:
  - `period_from` *(ISO 8601, напр. `2026-08-01T00:00:00`)* — начало периода.
  - `period_to` — конец периода.
  - `journal_data_type` — тип среза (`CURRENT`, `END_OF_DAY`, `END_OF_MONTH`, `END_OF_YEAR`).
  - `limit`, `offset`, `sort`, `page`.
- **Типы записей (`value_type`)**:
  - `DEVICE_DATA` — физические показания, полученные непосредственно с прибора.
  - `INTERPOLATED_LINEAR` — расчётные интерполированные значения (не используются для расчёта фактического расхода).

### 4. Уровень заряда батареи
- **Метод**: `GET /api/v1/devices/{device_id}/battery-level`
- **Поля ответа**: `value` (напряжение в вольтах, напр. `3.62`), `date`.

### 5. Температура прибора
- **Метод**: `GET /api/v1/devices/{device_id}/temperatures`
- **Поля ответа**: `value` (температура в °C, напр. `24.5`), `date`.

### 6. Журнал событий и аварий
- **Метод**: `GET /api/v1/devices/{device_id}/events`
- **События**: `MAGNET_WAS_DETECTED` (магнит), `CASE_WAS_OPENED` (вскрытие), `BATTERY_IS_LOW` (разряд), `FLOW_REVERSE` (обратный ход), `SYS_NO_DATA` (таймаут связи).

---

## 🔄 Стратегия отказоустойчивости и ретраев

В [`UnicBoard::getDeviceInfo()`](file:///Users/nikolaj/Projects/astroplate/public/api/telegram_bot/src/UnicBoard.php) реализована адаптивная стратегия чередования (до 4 попыток с логированием скорости ответа):

1. **Попытка 1**: `GET /api/v1/devices/{id}/info` (прямой опрос конкретного прибора).
2. **Попытка 2**: `POST /api/v1/devices/info` с телом `{"device_ids": [id]}` (точечный опрос по контракту API).
3. **Попытка 3**: Повторный `GET /api/v1/devices/{id}/info`.
4. **Попытка 4**: Повторный `POST /api/v1/devices/info` с телом `{"device_ids": [id]}`.

### Разделение ответственности:
- **`UnicBoard` (API-клиент)**: Отвечает за транспорт и структурную валидацию (`hasCompleteDeviceInfoPayload`). Значение `last_value: null` считается корректным ответом API (например, для новых непривязанных приборов) и не вызывает бесконечных ретраев.
- **`ReportService` (Бизнес-логика)**: Если в `/info` отсутствуют онлайн-показания (`last_value === null`), автоматически запрашивает исторический архив через `POST /devices/values` и формирует блок *«Последние сохранённые показания (нет текущих онлайн-данных)»*.

---

## 📊 Маппинг полей и коэффициенты расчёта

| Поле API | Назначение | Применение в коде |
|---|---|---|
| `device.manufacturer_serial_number` | Заводской номер модема | Информационное поле |
| `device_channel.serial_number` | Номер импульсного канала (1, 2...) | Идентификатор канала прибора |
| `device_meter.last_value` | Текущее показание счётчика | Базовое значение расхода |
| `device_meter.last_value_date` | Дата и время последнего замера | Дата актуальности показаний |
| `device_meter.unit_multiplier` | Коэффициент цены импульса (м³/имп) | Умножение сырых импульсов при необходимости |
| `device_meter.value_multiplier` | Масштабный множитель значения | Масштабирование итогового расхода |
| `values.value_type` | Тип значения (`DEVICE_DATA` / `INTERPOLATED_LINEAR`) | Фильтрация физических показаний для расчёта расхода |

---

## 🚀 Установка и настройка

### 1. Требования
- **PHP 8.2+** с расширениями `curl`, `json`, `mbstring`.
- **Composer** (опционально, для линтинга/проверок).
- **Python 3.9+** с библиотекой `requests` (для запуска `test_jupiter_api.py`).

### 2. Конфигурация окружения
Создайте файл `.env` в директории `public/api/telegram_bot/`:

```env
# Токен бота Telegram от @BotFather
TELEGRAM_BOT_TOKEN="123456789:ABCdefGHIjklMNOpqrSTUvwxYZ"

# Базовый URL и API токен UnicBoard
UNICBOARD_API_BASE="https://api.public.data-aggregator.unicboard.by"
UNICBOARD_API_TOKEN="your_unicboard_bearer_token_here"

# Часовой пояс
TIMEZONE="Europe/Minsk"

# Логирование (включение JSON-логов API запросов)
UNICBOARD_API_LOG_ENABLED="true"
```

---

## 🤖 Запуск Telegram-бота

Запуск бота в режиме консольного Long Polling демона:

```bash
cd public/api/telegram_bot
php bot.php
```

### Команды бота:
- `/start` — Приветствие и начало работы.
- `/help` — Справка по всем доступным командам.
- `/add <Номер> <UUID>` — Привязка прибора (напр. `/add 8524390 420de7d0-5e14-453d-8ad3-5a1dc3729e34`).
- `/list` — Список подключённых приборов с быстрыми кнопками.
- `/report` — Мгновенный сводный отчёт по всем приборам с текущими показаниями.
- `/archive` — Детальный архив расхода за текущий месяц.
- `/delete <Номер>` — Отвязка прибора.

---

## 🧪 Тестирование

Запуск полного набора тестов (149 тестов):

```bash
php public/api/telegram_bot/tests/run_tests.php
```

Запуск автономного тестового Python-скрипта:

```bash
python3 public/api/telegram_bot/test_jupiter_api.py
```

---

## 📝 Логирование и диагностика

Все события бота, ошибки и структурированные JSON-логи API-запросов автоматически записываются в файл **`storage/bot.log`** (путь можно переопределить через переменную `BOT_LOG_FILE` в `.env`), а также в стандартный поток `stderr`:

```json
{
  "tag": "UNICBOARD_API",
  "time": "2026-08-16T20:58:00+03:00",
  "endpoint": "GET /api/v1/devices/{id}/info",
  "device_id": "420de7d0-5e14-453d-8ad3-5a1dc3729e34",
  "attempt": 1,
  "request_variant": "get_device_id_info",
  "http_status": 200,
  "ok": true,
  "payload_count": 2,
  "duration_ms": 142.3,
  "errors": [],
  "extra": {
    "total_count": 1,
    "channels": [
      {
        "channel_number": 1,
        "has_meter": true,
        "last_value": 12.345,
        "last_value_date": "2026-08-16T18:00:00"
      }
    ]
  }
}
```

Просмотр логов в реальном времени:
```bash
tail -f storage/bot.log
```
