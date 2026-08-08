Критично

1. Race condition при записи JSON-файлов
load → modify → save не атомарно. При параллельных запросах (webhook + несколько пользователей) данные могут затираться.

php
// Сейчас (опасно):
function save_user_meters(array $data): void
{
    file_put_contents(user_storage_file(), json_encode(...));
}
// Лучше: атомарная запись через временный файл
function save_user_meters(array $data): void
{
    $file = user_storage_file();
    $tmp  = $file . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}
Для надёжности стоит добавить flock() перед чтением.

1. Отсутствие проверки webhook-источника
Любой может POST-нуть на endpoint бота и вызвать обработчик. Нужна проверка секретного токена Telegram:

php
// В run_webhook():
$secret = $config['webhook_secret'] ?? '';
if ($secret !== '' && ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== $secret) {
    http_response_code(403);
    exit;
}
🟡 Важно
3. http_get / http_post_json не обрабатывают ошибки cURL
Если соединение не удалось — curl_exec() возвращает false, а не строку. Потом json_decode((string)false) вернёт null без всякой диагностики.

php
$body = curl_exec($ch);
if ($body === false) {
    // сейчас: тихо возвращает [null_code, null] — непонятная ошибка
    // надо: логировать curl_error($ch) и возвращать явную ошибку
    $err = curl_error($ch);
    curl_close($ch);
    return [0, null]; // или бросать исключение
}
curl_close($ch);  // <-- также отсутствует curl_close!
4. curl_close() нигде не вызывается
Каждый http_get и http_post_json создаёт handle cURL и не освобождает его. В long-polling это утечка ресурсов на каждой итерации цикла.

1. Нет таймаута соединения (CURLOPT_CONNECTTIMEOUT)
CURLOPT_TIMEOUT = 30 — это общий таймаут. Если сервер не отвечает, long-polling loop может зависнуть на 30 секунд на каждом запросе. Добавьте:

php
CURLOPT_CONNECTTIMEOUT => 5,
6. device_lookup делает API-запрос на каждое сообщение
Даже когда прибор уже есть в локальном хранилище, функция всё равно вызывает get_all_devices(), если не нашла по serial/имени. В нормальной ситуации это лишний запрос. Убедитесь, что return null после шагов 1 и 2 срабатывает до API-вызова — сейчас логика правильная, но стоит добавить комментарий, чтобы это было явным.

🟢 Стиль / мелочи
7. strpos($data, 'month_') === 0 → лучше str_starts_with()
PHP 8+ предоставляет более читаемую замену:

php
// Сейчас:
if (strpos($data, 'month_') === 0) { ... }
// Лучше:
if (str_starts_with($data, 'month_')) { ... }
То же для add_, del_, /add , /del .

1. register_custom_device — UUID не валидируется
Пользователь передаёт UUID напрямую из команды. Стоит проверить формат перед сохранением:

php
if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid)) {
    send_message($chatId, "Неверный формат UUID.", $token);
    return;
}
9. registered_devices.json доступен по HTTP
Файл лежит в public/api/telegram_bot/ — это публичная директория. Файл с данными о приборах доступен всем. Либо переместить в директорию вне public/, либо заблокировать через .htaccess / nginx.

 1. TO_CMD — константа с HTML внутри, но без экранирования
В строке есть <code> — если когда-нибудь туда попадут динамические данные, это станет уязвимостью. Сейчас безопасно, но стоит держать в уме.

Итоговые приоритеты

# Проблема Приоритет

1 Race condition при записи JSON 🔴 Высокий
2 Нет проверки источника webhook 🔴 Высокий
3 cURL ошибки не обрабатываются 🟡 Средний
4 curl_close() отсутствует 🟡 Средний
5 Нет CONNECTTIMEOUT 🟡 Средний
6 registered_devices.json в public 🟡 Средний
7 strpos → str_starts_with 🟢 Низкий
8 UUID не валидируется 🟢 Низкий
