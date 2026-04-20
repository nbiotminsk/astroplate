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

$name = $input['name'] ?? '';
$phone = $input['phone'] ?? '';
$quantity = $input['quantity'] ?? null;
$serviceTitle = $input['serviceTitle'] ?? '';
$requestType = $input['requestType'] ?? '';
$message = $input['message'] ?? '';

// Валидация
if (empty($name) || empty($phone)) {
    echo json_encode(['error' => 'Missing required fields']);
    http_response_code(400);
    exit();
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

if ($requestType === 'consultation') {
    $messageText = "💼 Запрос на консультацию\n\n👤 Имя/Организация: {$name}\n📞 Телефон: {$phone}\n\n📅 Дата: " . $currentTime->format('d.m.Y H:i:s');
} elseif ($requestType === 'order') {
    $qty = (!is_null($quantity) && is_numeric($quantity) && $quantity > 0) ? $quantity : 1;
    $messageText = "🛒 Заказ товара\n\n👤 Имя/Организация: {$name}\n📞 Телефон: {$phone}\n📦 Товар: " . ($serviceTitle ?: 'Не указан') . "\n📊 Количество: {$qty} шт.\n\n📅 Дата: " . $currentTime->format('d.m.Y H:i:s');
} elseif ($requestType === 'contact') {
    $messageText = "📬 Новая заявка с сайта Meter.by\n\n👤 Имя: {$name}\n📞 Телефон: {$phone}\n💬 Сообщение:\n" . ($message ?: 'Нет сообщения') . "\n\n📅 Дата: " . $currentTime->format('d.m.Y H:i:s');
} else {
    $messageText = "📞 Заявка на обратный звонок\n\n👤 Имя/Организация: {$name}\n📞 Телефон: {$phone}\n🔧 Услуга: " . ($serviceTitle ?: 'Не указана') . "\n\n📅 Дата: " . $currentTime->format('d.m.Y H:i:s');
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
curl_close($ch);

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