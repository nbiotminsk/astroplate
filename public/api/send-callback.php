<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка предварительных запросов (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Получение данных из тела запроса
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['error' => 'Invalid JSON data']);
    http_response_code(400);
    exit();
}

$requestType = $input['requestType'] ?? '';
$name = $input['name'] ?? '';
$phone = $input['phone'] ?? '';
$email = $input['email'] ?? '';
$message = $input['message'] ?? '';

// Checkout specific fields
$address = $input['address'] ?? '';
$unp = $input['unp'] ?? '';
$checkoutType = $input['checkoutType'] ?? '';
$cartItems = $input['cartItems'] ?? [];
$total = $input['total'] ?? 0;

// Валидация
if ($requestType === 'contact') {
    if (empty($name) || empty($email)) {
        echo json_encode(['error' => 'Missing required fields (name, email)']);
        http_response_code(400);
        exit();
    }
} else {
    if (empty($name) || empty($phone)) {
        echo json_encode(['error' => 'Missing required fields (name, phone)']);
        http_response_code(400);
        exit();
    }
}

// Получаем переменные окружения (через getenv или конфигурационный файл)
$telegramBotToken = getenv('PUBLIC_TELEGRAM_BOT_TOKEN') ?: $_ENV['PUBLIC_TELEGRAM_BOT_TOKEN'] ?: '8243078295:AAGgXFk6LsCW9yYw9-Lh0EHRwH0rj4C8wLA';
$telegramChatId = getenv('PUBLIC_TELEGRAM_CHAT_ID') ?: $_ENV['PUBLIC_TELEGRAM_CHAT_ID'] ?: '358128306';

if (empty($telegramBotToken) || empty($telegramChatId)) {
    error_log('Missing Telegram configuration');
    echo json_encode(['error' => 'Server configuration error']);
    http_response_code(500);
    exit();
}

// Формирование сообщения в зависимости от типа запроса
$messageText = '';
$currentTime = new DateTime('now', new DateTimeZone('Europe/Minsk'));

if ($requestType === 'checkout') {
    $typeLabel = ($checkoutType === 'yur') ? "🏢 Юрлицо" : "👤 Физлицо";
    $messageText = "🔥 <b>Новый заказ с сайта</b>\n\n";
    $messageText .= "<b>Тип:</b> {$typeLabel}\n";
    if ($checkoutType === 'yur' && !empty($unp)) {
        $messageText .= "<b>УНП:</b> {$unp}\n";
        $messageText .= "<b>Организация:</b> {$name}\n";
    } else {
        $messageText .= "<b>ФИО:</b> {$name}\n";
    }
    $messageText .= "<b>Телефон:</b> {$phone}\n";
    $messageText .= "<b>Адрес:</b> {$address}\n\n";
    
    if (!empty($cartItems)) {
        $messageText .= "<b>🛒 Состав заказа:</b>\n";
        foreach ($cartItems as $index => $item) {
            $idx = $index + 1;
            $itemTitle = $item['title'] ?? 'Товар';
            $itemQty = $item['quantity'] ?? 1;
            $itemPrice = $item['price'] ?? 0;
            $itemSum = $itemQty * $itemPrice;
            $messageText .= "{$idx}. {$itemTitle} — {$itemQty} шт. x {$itemPrice} BYN = <b>{$itemSum} BYN</b>\n";
        }
        $messageText .= "\n💰 <b>Итого: {$total} BYN</b>\n";
    }
    
    $messageText .= "\n📅 Дата: " . $currentTime->format('d.m.Y H:i:s');
} elseif ($requestType === 'contact') {
    $messageText = "📬 <b>Новая заявка с сайта Teleofis24.by</b>\n\n";
    $messageText .= "👤 <b>Имя:</b> {$name}\n";
    $messageText .= "📧 <b>Email:</b> {$email}\n";
    $messageText .= "💬 <b>Сообщение:</b>\n" . ($message ?: 'Нет сообщения') . "\n";
    $messageText .= "\n📅 Дата: " . $currentTime->format('d.m.Y H:i:s');
} else {
    echo json_encode(['error' => 'Unknown request type']);
    http_response_code(400);
    exit();
}

// Отправка в Telegram
$telegramUrl = "https://api.telegram.org/bot{$telegramBotToken}/sendMessage";
$telegramData = [
    'chat_id' => (string)$telegramChatId,
    'text' => $messageText,
    'parse_mode' => 'HTML'
];

$ch = curl_init($telegramUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($telegramData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$telegramResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$responseData = json_decode($telegramResponse, true);

if ($httpCode !== 200 || !$responseData || !$responseData['ok']) {
    error_log('Telegram API error: ' . $telegramResponse);
    echo json_encode([
        'error' => 'Failed to send message to Telegram',
        'details' => $responseData,
        'telegramStatus' => $httpCode
    ]);
    http_response_code(500);
    exit();
}

echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
?>