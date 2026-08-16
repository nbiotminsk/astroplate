# UnicBoard API & Telegram Bot Integration

Документация по API сервиса **UnicBoard** (DataUnic), описанию доступных эндпоинтов, параметрам счетчиков **Юпитер** (`Jupiter / MM219`), а также инструкции по запуску Telegram-бота и тестового скрипта.

---

## 📡 Описание эндпоинтов UnicBoard API (`/api/v1`)

Базовый URL: `https://api.public.data-aggregator.unicboard.by`

Все закрытые эндпоинты требуют заголовок авторизации:
```
Authorization: Bearer <UNICBOARD_API_TOKEN>
Accept: application/json
```

---

### 1. Информация обо всех устройствах пользователя
- **Метод**: `GET /api/v1/devices/info` или `POST /api/v1/devices/info`
- **Query-параметры**: `limit` (int), `offset` (int), `page` (int), `sort` (string), `filter` (string).
- **Body (для POST)**: `{"device_ids": ["uuid1", "uuid2"]}`
- **Описание**: Возвращает список всех приборов пользователя, включая заводские номера, модель, тип учета, структуру импульсных каналов и шлюз передачи данных.
- **Структура ответа**:
  ```json
  {
    "ok": true,
    "count": 2,
    "total_count": 2,
    "errors": [],
    "payload": [
      {
        "id": "420de7d0-5e14-453d-8ad3-5a1dc3729e34",
        "manufacturer_serial_number": "8524390",
        "device_manufacturer": { "name": "NERO" },
        "device_modification": {
          "name": "MM219",
          "device_modification_type": {
            "name_ru": "Юпитер",
            "sys_name": "upiter",
            "type": "modem",
            "device_metering_type": { "name_ru": "Вода", "sys_name": "water" }
          }
        },
        "device_channel": [
          {
            "serial_number": 1,
            "inactivity_limit": 86400,
            "device_meter": [
              { "last_value": 0.0, "last_value_date": "2026-08-10T08:16:21", "unit_multiplier": 0.01 }
            ]
          }
        ]
      }
    ]
  }
  ```

  `manufacturer_serial_number` — заводской идентификатор устройства по контракту API,
  а `device_channel.serial_number` — номер импульсного канала. Ни одно из этих полей
  не подтверждено как физический номер водосчётчика. Бот использует номер, который
  пользователь сохраняет локально при `/add ID UUID`, и не подменяет его API-ID.

---

### 2. Информация по конкретному устройству
- **Метод**: `GET /api/v1/devices/{device_id}/info`
- **Path-параметры**: `device_id` (UUID)
- **Описание**: Возвращает детальные паспортные данные конкретного прибора по его UUID.

---

### 3. Архив и текущий журнал показаний
- **Метод**: `POST /api/v1/devices/values`
- **Query-параметры**:
  - `period_from` (*Обязательный*): дата начала периода в формате ISO 8601 (например `2026-08-01T00:00:00`).
  - `period_to`: дата окончания периода.
  - `journal_data_type`: тип журнала (`CURRENT`, `END_OF_DAY`, `END_OF_MONTH`, `END_OF_YEAR`).
  - `iteration_interval`: шаг группировки (`30 minute`, `60 minutes`, `1 day`, `1 week`, `1 month`).
  - `limit`, `offset`, `sort`, `page`.
- **Body**: `{"devices_id": ["<device_id_uuid>"]}`
- **Описание**: Возвращает сохраненные показания по каналам устройства за указанный период.
- **Основные поля ответа**:
  - `channel_number`: номер канала/импульсного входа (1, 2...).
  - `value`: расчитанное показание (например в м³).
  - `value_raw`: необработанное показание импульсов.
  - `kind`: тип ресурса (`COMMON_CONSUMED` — объем потребления).
  - `date`: дата и время среза.
  - `date_created`: дата сохранения записи в базу данных.
  - `value_type`: `DEVICE_DATA` или `INTERPOLATED_LINEAR`.

---

### 4. Уровень заряда батареи / питание
- **Метод**: `GET /api/v1/devices/{device_id}/battery-level`
- **Path-параметры**: `device_id` (UUID)
- **Query-параметры**: `limit`, `offset`, `page`, `sort`, `filter`.
- **Описание**: История замера напряжения элемента питания модема/счетчика.
- **Поля ответа**: `sensor_id` (`-1`), `value` (напряжение в вольтах, например `3.57`), `date`.

---

### 5. Температура прибора
- **Метод**: `GET /api/v1/devices/{device_id}/temperatures`
- **Path-параметры**: `device_id` (UUID)
- **Query-параметры**: `limit`, `offset`, `page`, `sort`, `filter`.
- **Описание**: Данные о температуре корпуса прибора или окружающего воздуха в °C (например `27.0`).

---

### 6. Журнал событий и аварий
- **Метод**: `GET /api/v1/devices/{device_id}/events`
- **Path-параметры**: `device_id` (UUID)
- **Query-параметры**: `limit`, `offset`, `page`, `sort`, `filter`.
- **Описание**: Аварийные сообщения, манипуляции и системные логи.
- **Ключевые коды событий (`type`)**:
  - `MAGNET_WAS_DETECTED` — воздействие внешним магнитом.
  - `CASE_WAS_OPENED` — вскрытие корпуса или клеммной крышки.
  - `BATTERY_IS_LOW` / `LOW_BATTERY_CAPACITY` — разряд батареи.
  - `FLOW_REVERSE` / `NO_WATER` — обратный поток воды / отсутствие теплоносителя.
  - `ERROR_SENSOR_TEMPERATURE` / `SYS_NO_DATA` — ошибки датчиков или связь.

---

### 7. Детализированные профили мощности и потребления
- **Метод**: `GET /api/v1/devices/{device_id}/profiles`
- **Path-параметры**: `device_id` (UUID)
- **Query-параметры**: `limit`, `offset`, `page`, `sort`, `filter`.
- **Описание**: Накопленные профили интервального потребления.
- **Поля**: `granularity_s` (`MINUTE_01`, `MINUTE_15`, `MINUTE_60`, `DAY_1`, `MONTH_1`), `profile_kind` (`VOLUME_CONSUMPTION`, `VOLUME_CONSUMPTION_DELTA`, `ENERGY_A_N` и т.д.), `date_start`, `date_end`.

---

### 8. Внутренние часы прибора
- **Метод**: `GET /api/v1/devices/{device_id}/clocks`
- **Path-параметры**: `device_id` (UUID)
- **Описание**: Мониторинг синхронизации времени часов счетчика (`device_clock`, `out_of_sync_s`, `out_of_sync_type`: `synced`, `out_of_sync_warning`, `out_of_sync_critical`).

---

### 9. Время непрерывной работы (Uptime)
- **Метод**: `GET /api/v1/devices/{device_id}/uptimes`
- **Path-параметры**: `device_id` (UUID)
- **Описание**: Uptime устройства в секундах от перезагрузки.

---

### 10. Проверка прав авторизации
- **Метод**: `GET /api/v1/permissions`
- **Описание**: Проверка прав текущего API-токена.

---

## 🌊 Специфика параметров счетчиков/модемов «Юпитер» (`sys_name: "upiter"`)

Модем **Юпитер (MM219)** используется для подключения счетчиков холодной и горячей воды:
- **Модификация**: `MM219` (`device_modification_type.name_ru: "Юпитер"`, `sys_name: "upiter"`).
- **Количество импульсных каналов**: 2 независимых входа (`serial_number: 1` и `serial_number: 2`).
- **Множители веса импульса (`unit_multiplier`)**:
  - Канал 1: `0.01` м³/имп (10 литров/импульс).
  - Канал 2: `0.001` м³/имп (1 литр/импульс).
- **Рабочее напряжение батареи**: ~`3.0 - 3.6 В`.
- **Температура работы**: в диапазоне `-10...+50 °C`.

---

## 🛠 Тестовый скрипт Python

В каталоге расположен файл [test_jupiter_api.py](file:///Users/nikolaj/Projects/astroplate/public/api/telegram_bot/test_jupiter_api.py) для автономной проверки работы API.

### Запуск:
```bash
python3 public/api/telegram_bot/test_jupiter_api.py
```

Скрипт выполняет:
1. Авторизацию по токену из `.env`.
2. Запрос всех устройств и фильтрацию моделей «Юпитер».
3. Вывод текущих значений по импульсным каналам.
4. Запрос истории заряда батареи и температуры.
5. Запрос архива показаний за последние 7 дней (`POST /api/v1/devices/values`).

---

## 🤖 Запуск Telegram-бота (PHP)

Бот работает через long-polling CLI:

```bash
cd public/api/telegram_bot
php bot.php
```

Настройки авторизации и токенов хранятся в файле `.env`.
