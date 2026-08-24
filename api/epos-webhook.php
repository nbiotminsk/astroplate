<?php
/**
 * Webhook-обработчик (Callback) от сервиса "Хутки Грош" для подтверждения оплаты E-POS.
 * Принимает GET запрос вида: epos-webhook.php?purchaseid=123456789
 */

header('Content-Type: application/json; charset=utf-8');

// Получаем ID счета
$purchaseId = $_GET['purchaseid'] ?? null;

if (!$purchaseId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing purchaseid parameter']);
    exit;
}

// -------------------------------------------------------------
// КОНФИГУРАЦИЯ
// -------------------------------------------------------------
$API_URL = getenv('HUTKIGROSH_API_URL') ?: 'https://trial.hgrosh.by/API/v1';
$CREDENTIALS_USER = getenv('HUTKIGROSH_USER');
$CREDENTIALS_PWD = getenv('HUTKIGROSH_PASSWORD');

// Telegram конфигурация
$telegramBotToken = getenv('PUBLIC_TELEGRAM_BOT_TOKEN') ?: ($_ENV['PUBLIC_TELEGRAM_BOT_TOKEN'] ?? '8243078295:AAGgXFk6LsCW9yYw9-Lh0EHRwH0rj4C8wLA');
$telegramChatId = getenv('PUBLIC_TELEGRAM_CHAT_ID') ?: ($_ENV['PUBLIC_TELEGRAM_CHAT_ID'] ?? '358128306');

if (!$CREDENTIALS_USER || !$CREDENTIALS_PWD) {
    // В случае отсутствия конфига возвращаем 200, чтобы Hutki Grosh не спамил повторами,
    // но логируем критическую ошибку.
    error_log('E-POS Webhook: API credentials are not configured.');
    http_response_code(200);
    echo json_encode(['error' => 'Server credentials misconfiguration']);
    exit;
}

// Вспомогательная функция для cURL запросов
function sendRequest($url, $data, $cookies, $method = 'POST') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    if ($data !== null) {
        $json_data = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        $headers[] = 'Content-Length: ' . strlen($json_data);
    }
    
    if (!empty($cookies)) {
        $cookieHeader = '';
        foreach ($cookies as $key => $val) {
            $cookieHeader .= "$key=$val; ";
        }
        curl_setopt($ch, CURLOPT_COOKIE, trim($cookieHeader));
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    if (strpos($url, 'trial.hgrosh.by') !== false) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        return ['error' => curl_error($ch)];
    }
    
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header_text = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    curl_close($ch);
    
    $responseCookies = [];
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header_text, $matches);
    foreach($matches[1] as $item) {
        parse_str($item, $cookieData);
        foreach($cookieData as $k => $v) {
            $responseCookies[$k] = $v;
        }
    }
    
    return [
        'body' => json_decode($body, true) ?? $body,
        'cookies' => array_merge((array)$cookies, $responseCookies),
        'raw_body' => $body
    ];
}

// -------------------------------------------------------------
// ЭТАП 1: Аутентификация
// -------------------------------------------------------------
$loginData = [
    'user' => $CREDENTIALS_USER,
    'pwd' => $CREDENTIALS_PWD
];

$loginRes = sendRequest($API_URL . '/Security/LogIn', $loginData, []);

if (isset($loginRes['error']) || $loginRes['body'] !== true) {
    error_log('E-POS Webhook: Authentication to Hutki Grosh failed: ' . json_encode($loginRes));
    http_response_code(200);
    echo json_encode(['error' => 'API login failed']);
    exit;
}

$authCookies = $loginRes['cookies'];

// -------------------------------------------------------------
// ЭТАП 2: Запрос деталей счета
// -------------------------------------------------------------
$billRes = sendRequest($API_URL . "/Invoicing/Bill({$purchaseId})", null, $authCookies, 'GET');

// -------------------------------------------------------------
// ЭТАП 3: Завершение сессии
// -------------------------------------------------------------
sendRequest($API_URL . '/Security/LogOut', null, $authCookies);

// -------------------------------------------------------------
// Обработка результата
// -------------------------------------------------------------
if (isset($billRes['error'])) {
    error_log("E-POS Webhook: Failed to fetch bill {$purchaseId}: " . $billRes['error']);
    http_response_code(200);
    echo json_encode(['error' => 'Failed to fetch bill details']);
    exit;
}

$bill = $billRes['body'] ?? null;
if (!$bill || !isset($bill['statusEnum'])) {
    error_log("E-POS Webhook: Invalid response format for bill {$purchaseId}: " . $billRes['raw_body']);
    http_response_code(200);
    echo json_encode(['error' => 'Invalid bill response format']);
    exit;
}

$statusEnum = (int)$bill['statusEnum'];
$invId = $bill['invId'] ?? 'Неизвестный заказ';
$amt = $bill['amt'] ?? 0.0;
$fullName = $bill['fullName'] ?? 'ФИО не указано';
$phone = $bill['mobilePhone'] ?? 'Телефон не указан';
$info = $bill['info'] ?? '';

// Статус 5 = Оплачен
if ($statusEnum === 5) {
    // Формируем красивое сообщение для Telegram
    $currentTime = new DateTime('now', new DateTimeZone('Europe/Minsk'));
    
    $messageText = "✅ <b>Счет E-POS успешно оплачен!</b>\n\n";
    $messageText .= "🆔 <b>ID Счета:</b> <code>{$purchaseId}</code>\n";
    $messageText .= "📦 <b>Заказ №:</b> <code>{$invId}</code>\n";
    $messageText .= "👤 <b>Плательщик:</b> {$fullName}\n";
    $messageText .= "📞 <b>Телефон:</b> {$phone}\n";
    $messageText .= "💰 <b>Сумма:</b> <b>{$amt} BYN</b>\n";
    if (!empty($info)) {
        $messageText .= "ℹ️ <b>Назначение:</b> {$info}\n";
    }
    $messageText .= "\n📅 <b>Время оплаты:</b> " . $currentTime->format('d.m.Y H:i:s');

    // Отправка в Telegram
    $telegramUrl = "https://api.telegram.org/bot{$telegramBotToken}/sendMessage";
    $telegramData = [
        'chat_id' => (string)$telegramChatId,
        'text' => $messageText,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($telegramUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $telegramData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $telegramResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($telegramResponse === false || $httpCode !== 200) {
        error_log("E-POS Webhook Telegram Error: " . ($curlError ?: $telegramResponse));
    }
    
    echo json_encode([
        'success' => true,
        'paid' => true,
        'message' => 'Payment processed and notified.'
    ]);
} else {
    // Счет не оплачен (например, статус другой)
    echo json_encode([
        'success' => true,
        'paid' => false,
        'statusEnum' => $statusEnum,
        'message' => "Bill exists but status is {$statusEnum} (not paid)."
    ]);
}
?>
