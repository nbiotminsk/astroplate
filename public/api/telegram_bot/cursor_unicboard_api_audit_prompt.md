# Cursor Prompt — Full UnicBoard API Audit and Telegram Bot Fix

## Task: Full audit of the Telegram bot, UnicBoard API, and data mapping

You are working on an existing PHP Telegram bot located at:

`public/api/telegram_bot/`

Repository:

`https://github.com/nbiotminsk/astroplate`

The main goal is **NOT to rewrite the project from scratch**.

First fully investigate the existing implementation, the `api.json` API specification, and the actual API response structures. Only after the investigation should you make targeted fixes.

There is a particularly important production issue:

> After a long period without using the Telegram bot (approximately 12 hours), the first request for meter readings sometimes returns `No data`, while a second request shortly afterwards returns the correct readings.

Do not simply increase the retry count. Determine the actual cause from the API contract, the code, and real API responses.

---

## STEP 1 — Inspect the project structure

Before changing anything, inspect:

- `public/api/telegram_bot/bot.php`
- `public/api/telegram_bot/config.php`
- `public/api/telegram_bot/api.json`
- `public/api/telegram_bot/src/BotHandler.php`
- `public/api/telegram_bot/src/ReportService.php`
- `public/api/telegram_bot/src/MeterService.php`
- `public/api/telegram_bot/src/UnicBoard.php`
- `public/api/telegram_bot/src/Storage.php`
- `public/api/telegram_bot/src/Repository/*`
- `public/api/telegram_bot/src/Command/*`
- DTOs/models, if present
- tests, fixtures, and mock API responses, if present

Search the entire project for all usages of:

```text
device_id
meter_id
channel_number
serial_number
manufacturer_serial_number
kind
last_value
last_value_date
device_meter
device_channel
```

Do not modify anything yet.

Create a mental map of the actual execution flow:

```text
Telegram update
    ↓
BotHandler
    ↓
CommandDispatcher
    ↓
specific command
    ↓
ReportService
    ↓
UnicBoard
    ↓
UnicBoard API
    ↓
ReportService
    ↓
MeterService
    ↓
Telegram response
```

Identify the exact flow used when the user requests current meter readings.

---

# STEP 2 — Fully inspect `api.json`

Treat `api.json` as the primary API contract.

Do not infer API behavior from PHP variable names.

Analyze all endpoints related to:

- devices
- device info
- values
- meters
- channels
- events
- temperatures
- battery
- profiles
- uptime
- clocks

Pay particular attention to:

```text
GET /api/v1/devices/{device_id}/info
POST /api/v1/devices/values
GET /api/v1/devices/{device_id}/battery-level
GET /api/v1/devices/{device_id}/temperatures
GET /api/v1/devices/{device_id}/events
```

For each relevant endpoint determine:

1. Required parameters
2. Optional parameters
3. Default values
4. Request fields
5. Response fields
6. Nullable fields
7. Array structures
8. UUID fields
9. Identifier fields
10. Device-level fields
11. Channel-level fields
12. Meter-level fields
13. Reading/value fields
14. Connection/status fields
15. Error fields

---

# STEP 3 — Clearly distinguish all identifiers

Explicitly distinguish:

```text
device.id
device.manufacturer_serial_number

device_channel.id
device_channel.serial_number

device_meter.id
device_meter.device_id
device_meter.device_channel_id
device_meter.kind

values.device_id
values.channel_number
values.meter_id
```

Do NOT automatically assume:

```text
device_channel.serial_number = physical water meter number
```

Do NOT automatically assume:

```text
manufacturer_serial_number = physical water meter number
```

Do NOT automatically assume:

```text
device_meter.kind = physical water meter number
```

Determine the actual meaning of every field from `api.json` and, where possible, real API responses.

---

# STEP 4 — Investigate ALL meter-related data

Search `api.json` for:

```text
meter
meter_id
meter_number
serial_number
manufacturer_serial_number
kind
number
device_meter
billing
```

Determine whether the API exposes the physical/manufacturer serial number of the water meter.

The key question is:

> Can the physical water meter number, for example `8527038`, be obtained directly from the API?

If YES:

- identify the endpoint;
- identify the exact field;
- provide the JSON path;
- show an example response structure;
- explain when the field may be missing.

If NO:

- explicitly state that the API does not expose it;
- identify the closest available identifier;
- do not invent a relationship between UUIDs and physical meter numbers.

Pay special attention to:

```text
device_meter.kind
```

There is a hypothesis that this field may contain the meter number.

Do not assume this is true. Verify it.

---

# STEP 5 — Inspect real API responses

`api.json` describes the contract, but the real server may return additional fields.

Search the repository for:

- saved JSON responses;
- fixtures;
- logs;
- debug output;
- API examples;
- test data.

If it is possible to safely execute API requests using the existing project configuration, inspect real responses.

Never expose or print:

- API tokens;
- Authorization headers;
- Telegram bot tokens;
- passwords;
- secrets.

At minimum inspect real responses for:

```text
GET /api/v1/devices/{device_id}/info
POST /api/v1/devices/values
```

For `/info`, inspect:

```text
payload
device
device.id
device.manufacturer_serial_number
device.is_alive

device_channel[]
device_channel[].id
device_channel[].serial_number
device_channel[].is_alive
device_channel[].inactivity_limit
device_channel[].last_date_event_no_data

device_channel[].device_meter[]
device_meter[].id
device_meter[].device_id
device_meter[].device_channel_id
device_meter[].kind
device_meter[].is_alive
device_meter[].last_value
device_meter[].last_value_date
device_meter[].unit_multiplier
device_meter[].value_multiplier
```

If additional fields are returned by the real API, document them.

---

# STEP 6 — Fully investigate `/values`

Inspect the request currently generated by `UnicBoard.php`.

Determine whether the bot sends:

```text
limit
period_from
period_to
end_of_day
journal_data_type
channel_number
device_id
```

and any other supported parameters.

Pay special attention to:

```text
journal_data_type
```

and its possible values:

```text
CURRENT
END_OF_DAY
END_OF_MONTH
END_OF_YEAR
```

Also inspect:

```text
end_of_day
```

and its default value.

Determine exactly what request the current PHP code sends to the API.

Do not assume that an empty `payload` means an API failure.

An empty payload may be a valid response caused by filtering parameters.

---

# STEP 7 — Analyze every `/values` record

Determine the exact meaning of:

```text
device_id
channel_number
meter_id
value
value_raw
last_value
last_value_date
value_type
journal_data_type
kind
tariff_number
```

Determine:

- What is `value`?
- What is `last_value`?
- What is `value_raw`?
- What is `last_value_date`?
- What does `CURRENT` mean?
- What does `END_OF_DAY` mean?
- What does `END_OF_MONTH` mean?
- What does `END_OF_YEAR` mean?
- Can `value_type` be `INTERPOLATED_LINEAR`?
- Which values represent actual meter readings?
- Which values may be calculated/interpolated?

Do not simply use the last element of the `/values` array as the current reading without checking its type.

---

# STEP 8 — Investigate the "after 12 hours" problem

Analyze these API fields:

```text
inactivity_limit
last_date_event_no_data
is_alive
last_value
last_value_date
```

Determine whether the behavior after a long idle period can be related to:

- inactivity;
- missing device events;
- delayed data;
- cold API state;
- `/info`;
- `/values`;
- incorrect `/values` parameters;
- caching;
- PHP state;
- Telegram webhook/long polling;
- another factor.

Do not state a cause unless it is supported by actual evidence.

---

# STEP 9 — Audit `UnicBoard.php`

Inspect especially:

```text
getDeviceInfo()
getDeviceValues()
getBattery()
getTemperature()
```

Check:

- HTTP status handling;
- API `ok`;
- `payload`;
- `count`;
- `total_count`;
- `errors`;
- timeout;
- retry logic;
- retry delays;
- JSON decoding;
- HTTP errors;
- API errors;
- empty payload handling.

Pay particular attention to logic similar to:

```php
'ok' => !empty($payload)
```

If the API has its own:

```text
ok
```

field, do not replace its meaning with:

```text
!empty(payload)
```

These are separate concepts:

```text
request succeeded
```

and:

```text
request returned data
```

---

# STEP 10 — Add safe diagnostic logging

Before changing the business logic, add temporary diagnostic logging.

For every API request log:

```text
timestamp
endpoint
device_id
attempt
HTTP status
API ok
payload exists
payload count
total_count
errors
```

For `/info`, additionally log:

```text
device id
manufacturer_serial_number
is_alive
number of channels

channel number
channel is_alive
inactivity_limit
last_date_event_no_data

meter id
meter kind
meter is_alive
last_value
last_value_date
unit_multiplier
value_multiplier
```

For `/values`, log:

```text
number of records
device_id
channel_number
meter_id
value
last_value
last_value_date
value_type
journal_data_type
kind
```

Never log secrets.

The logging should make it possible to compare:

```text
first request after long idle period
```

with:

```text
second request shortly afterwards
```

---

# STEP 11 — Fix current-reading retrieval

The preferred architecture should be:

```text
GET /devices/{device_id}/info
        ↓
device_channel[]
        ↓
device_meter[]
        ↓
last_value
last_value_date
        ↓
CURRENT READING
```

If `/info` contains:

```text
last_value
last_value_date
```

the bot must be able to display the current reading even when:

```text
/values → payload=[]
```

Do not make `/values` a mandatory dependency for displaying a current reading.

---

# STEP 12 — Separate current data from history

Use:

```text
/info
```

for:

```text
current reading
current reading date
meter status
channel status
```

Use:

```text
/values
```

for:

```text
history
previous readings
consumption
charts
archives
```

Do not unnecessarily mix these two responsibilities.

---

# STEP 13 — Fix retry semantics

Retry only when a retry is justified.

Distinguish:

```text
network error
timeout
HTTP error
invalid JSON
API ok=false
API ok=true + payload=[]
API ok=true + data
```

For example:

```text
network/timeout/HTTP error → retry
API ok=false → potentially retry
API ok=true + empty payload → NOT automatically an error
```

For `/info`, define what constitutes an incomplete response.

If a response is:

```text
HTTP 200
ok=true
payload exists
but device_channel is missing
```

determine whether that should trigger a retry based on actual API behavior.

Never introduce infinite retries.

---

# STEP 14 — Fix `ReportService`

After understanding the API, structure `ReportService` logically:

```text
1. Fetch device info
2. Extract current readings
3. Extract channel/meter status
4. Fetch values only when history/consumption is required
5. Use values for historical calculations
6. Build the Telegram report
```

Important behavior:

```text
/info contains last_value
+
/values returns empty payload
=
current reading must still be displayed
```

---

# STEP 15 — Fix water-meter number handling

Do not use:

```text
device_channel.serial_number
```

as the physical water-meter number.

Investigate whether the physical number is available through:

```text
device_meter.kind
```

or another field.

If the API does not provide the physical meter number:

- do not invent it;
- use the existing local database value;
- correctly associate it with `device_id`, channel, and/or `meter_id`.

If the physical meter number is already stored in `UserRepository`, preserve that business logic.

---

# STEP 16 — Audit local database/storage mapping

Inspect:

```text
UserRepository
MeterRepository
Storage
JsonMeterCacheRepository
```

Determine which fields are stored for each meter:

```text
chat_id
device_id
meter_id
channel_number
meter_number
name
```

If the physical water-meter number already exists locally, do not replace it with an API UUID.

---

# STEP 17 — Audit `MeterService`

Verify:

```text
extractRecordValue()
extractRecordDate()
```

correctly handle:

```text
device_channel
device_meter[]
last_value
last_value_date
```

Test:

- multiple `device_meter` entries;
- multiple channels;
- `null`;
- `0`;
- numeric strings;
- missing `last_value`;
- empty arrays.

Do not use `empty()` where `0` is a valid meter value.

---

# STEP 18 — Verify multi-channel handling

The bot must correctly support:

```text
channel 1
channel 2
channel N
```

and must not confuse:

```text
channel_number
channel.serial_number
meter_id
physical meter number
device manufacturer serial number
```

Create an explicit internal mapping:

```text
device_id
    ↓
channel_number
    ↓
meter_id
    ↓
physical_meter_number
```

where the physical meter number is actually available.

---

# STEP 19 — Add/update tests

Create tests for at least these cases.

### Test 1 — Normal

`/info` contains:

```text
last_value
last_value_date
```

and `/values` contains records.

Expected:

```text
reading is displayed
```

### Test 2 — `/values` empty

`/info` contains:

```text
last_value
last_value_date
```

but:

```text
/values → payload=[]
```

Expected:

```text
current reading from /info is displayed
```

### Test 3 — Incomplete `/info`

`/info` temporarily returns incomplete data.

Expected:

```text
retry
```

according to the defined retry policy.

### Test 4 — `/values` contains data but `/info` is unavailable

Determine the correct fallback behavior based on the API contract and business logic.

Do not invent behavior without justification.

### Test 5 — Interpolated data

`value_type=INTERPOLATED_LINEAR`.

Verify that the bot does not incorrectly treat an interpolated value as a physical meter reading if business logic says otherwise.

### Test 6 — Multiple channels

### Test 7 — `last_value = 0`

### Test 8 — Missing `last_value`

### Test 9 — Empty `device_meter[]`

### Test 10 — HTTP 200 + `ok=true` + empty payload

This must be distinguished from an API/network error.

---

# STEP 20 — Reproduce the long-idle problem

Create a test/simulation representing:

```text
First request after a long idle period
        ↓
/info
        ↓
/values
```

followed by:

```text
Second request shortly afterwards
```

Compare:

```text
HTTP status
API ok
payload
channels
last_value
last_value_date
is_alive
last_date_event_no_data
```

If the logs show that the API is the source of the problem, do not hide it with excessive retries.

---

# STEP 21 — Preserve existing functionality

Before modifying code, identify functionality that already works:

- adding meters;
- deleting meters;
- renaming meters;
- reading current values;
- consumption calculation;
- history;
- Telegram buttons;
- notifications;
- cache;
- authorization;
- webhook/long polling.

After the changes, all existing functionality must continue to work.

---

# STEP 22 — Do not unnecessarily rewrite the architecture

Do NOT migrate to:

- another framework;
- another HTTP client;
- another database;
- another API;
- another storage system.

Work with the existing architecture.

Changes must be minimal and justified.

---

# STEP 23 — Produce an investigation report before major changes

Before making major changes, provide a concise technical report containing:

```text
1. Current execution flow
2. API endpoints currently used
3. All relevant data available from the API
4. Exact location of the physical water-meter number, if available
5. Actual meaning of meter.kind
6. Actual meaning of meter_id
7. Actual meaning of manufacturer_serial_number
8. Actual meaning of channel.serial_number
9. Evidence for the "no data after long idle" problem
10. Files that need modification
11. Exact changes proposed
```

If the cause has not yet been proven, explicitly state:

```text
CAUSE NOT PROVEN — REAL API RESPONSE/LOG REQUIRED
```

Never present a hypothesis as a confirmed fact.

---

# STEP 24 — Only after the investigation, modify the code

After the cause is established:

1. Modify `UnicBoard.php`.
2. Modify `ReportService.php` if necessary.
3. Modify `MeterService.php` if necessary.
4. Modify repositories/storage only if necessary.
5. Add/update tests.
6. Add temporary diagnostic logging where useful.
7. Run PHP syntax checks.
8. Run all available tests.
9. Check for runtime errors.
10. Review the final diff for unnecessary changes.

---

# STEP 25 — Final report

At the end provide:

## Changed files

```text
- file 1
- file 2
- file 3
```

## What was fixed

```text
1. ...
2. ...
3. ...
```

## Root cause

Clearly separate:

```text
PROVEN
```

from:

```text
HYPOTHESIS
```

## API field mapping

Create a table:

| API field | Meaning | PHP usage |
|---|---|---|
| `device.manufacturer_serial_number` | Device manufacturer serial number | ... |
| `device_channel.serial_number` | Channel serial number | ... |
| `device_meter.last_value` | Latest meter value | ... |
| `device_meter.last_value_date` | Date of latest meter value | ... |
| `values.meter_id` | Internal meter UUID | ... |
| `device_meter.kind` | Determine from API/real response | ... |

## Critical final question

Answer explicitly:

> Can the physical water-meter serial number be obtained automatically from the API?

The answer must be based only on:

1. `api.json`;
2. actual API responses;
3. existing project data.

Do not guess.

---

# FINAL RULES

The most important principle is:

```text
ACTUAL API RESPONSE
        ↓
API CONTRACT (api.json)
        ↓
PHP PARSER
        ↓
BUSINESS LOGIC
        ↓
TELEGRAM RESPONSE
```

Verify every layer.

Do NOT start by increasing retries.

Do NOT assume that an empty payload means an API failure.

Do NOT assume that `serial_number` means physical meter number.

Do NOT assume that `kind` means physical meter number.

Do NOT assume that `manufacturer_serial_number` means physical meter number.

Do NOT assume that `meter_id` contains the physical meter number.

Do not invent API fields.

Do not modify the database schema unless necessary.

Do not expose or commit secrets.

Do not remove working functionality.

The final implementation should use `device_meter.last_value` / `last_value_date` from `/info` as the primary source for the current meter reading whenever those fields are available, while `/values` should primarily serve historical/archive/consumption purposes.
