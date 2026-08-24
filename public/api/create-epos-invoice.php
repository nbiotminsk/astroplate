<?php
/**
 * Скрипт для выставления счетов E-POS через сервис "Хутки Грош".
 * Принимает POST запрос в формате JSON.
 */

header('Content-Type: application/json; charset=utf-8');

// Позволяет запросы с других доменов (CORS), если нужно во время разработки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Если метод OPTIONS, возвращаем 200 OK (Preflight request)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// -------------------------------------------------------------
// КОНФИГУРАЦИЯ
// -------------------------------------------------------------

// -------------------------------------------------------------
// ИНСТРУКЦИЯ ПО НАСТРОЙКЕ ПЕРЕМЕННЫХ ОКРУЖЕНИЯ:
// 1. Если на сервере стоит Apache, вы можете добавить эти переменные
//    в файл .htaccess:
//      SetEnv HUTKIGROSH_USER "your_email@domain.com"
//      SetEnv HUTKIGROSH_PASSWORD "your_secret_password"
//      SetEnv HUTKIGROSH_API_URL "https://www.hutkigrosh.by/API/v1"
//      SetEnv HUTKIGROSH_ERIP_ID "40000001"
//
// 2. Если у вас Nginx + PHP-FPM, добавьте в конфигурационный файл пула (www.conf):
//      env[HUTKIGROSH_USER] = "your_email@domain.com"
//      env[HUTKIGROSH_PASSWORD] = "your_secret_password"
//      env[HUTKIGROSH_API_URL] = "https://www.hutkigrosh.by/API/v1"
//
// 3. Или в панелях управления типа cPanel/Plesk/FastPanel задайте их 
//    в разделе настроек "Environment Variables" (Переменные окружения) для сайта.
// -------------------------------------------------------------

$API_URL = getenv('HUTKIGROSH_API_URL') ?: 'https://trial.hgrosh.by/API/v1'; // Для продакшена: https://www.hutkigrosh.by/API/v1
$CREDENTIALS_USER = getenv('HUTKIGROSH_USER'); // Ваш логин (из ENV)
$CREDENTIALS_PWD = getenv('HUTKIGROSH_PASSWORD'); // Ваш пароль (из ENV)
$ERIP_ID = getenv('HUTKIGROSH_ERIP_ID') ?: 40000001; // Идентификатор услуги E-POS (из ENV или по умолчанию)

if (!$CREDENTIALS_USER || !$CREDENTIALS_PWD) {
    http_response_code(500);
    echo json_encode(['error' => 'API credentials are not configured on the server']);
    exit;
}

// -------------------------------------------------------------

// Получаем входные данные
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    // Попробуем получить из $_POST, если отправлено как x-www-form-urlencoded
    $input = $_POST;
}

if (empty($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or empty input']);
    exit;
}

// Извлечение параметров счета
$fullName = $input['fullName'] ?? 'Неизвестный клиент';
$amount = floatval($input['amount'] ?? 0);
$phone = $input['phone'] ?? '';
$email = $input['email'] ?? null;
$address = $input['address'] ?? '';
$orderId = $input['orderId'] ?? uniqid('ORDER-'); // Генерируем уникальный ID, если не передан

// Назначение платежа и список товаров
$cartItems = $input['items'] ?? [];
$infoText = "Оплата заказа $orderId";
$productsPayload = [];

if (!empty($cartItems) && is_array($cartItems)) {
    $itemsSummary = [];
    foreach ($cartItems as $index => $item) {
        $name = $item['name'] ?? 'Товар';
        $quantity = (int)($item['quantity'] ?? 1);
        $price = floatval($item['price'] ?? 0);
        
        $itemsSummary[] = "$name ($quantity шт.)";
        
        $productsPayload[] = [
            'invItemId' => (string)($item['id'] ?? ($orderId . '-' . $index)),
            'desc' => $name,
            'count' => $quantity,
            'amt' => $price
        ];
    }
    // Формируем текст: "Товар 1 (2 шт.), Товар 2 (1 шт.)"
    $infoText = implode(', ', $itemsSummary);
} else {
    // Оставляем fallback для itemName
    $itemName = $input['itemName'] ?? 'Оплата услуг из корзины';
    $infoText = $itemName;
    $productsPayload[] = [
        'invItemId' => $orderId,
        'desc' => $itemName,
        'count' => 1,
        'amt' => $amount
    ];
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Amount must be greater than zero']);
    exit;
}

/**
 * Вспомогательная функция для отправки запросов к API
 */
function sendRequest($url, $data, $cookies, $method = 'POST') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    if ($data !== null) {
        // Кодируем данные. Обратите внимание: json_encode по умолчанию экранирует слэши,
        // что необходимо для правильной отправки .NET формата дат \/Date(...)\/
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
    curl_setopt($ch, CURLOPT_HEADER, true); // Возвращать заголовки для извлечения Cookie
    
    // В тестовой среде могут быть проблемы с самоподписанными сертификатами
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
    
    // Извлекаем куки из ответа
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
    http_response_code(401);
    echo json_encode([
        'error' => 'Authentication to Hutki Grosh failed',
        'details' => $loginRes['error'] ?? $loginRes['raw_body']
    ]);
    exit;
}

$authCookies = $loginRes['cookies'];

// -------------------------------------------------------------
// ЭТАП 2: Выставление счета
// -------------------------------------------------------------
$currentTimeMs = time() * 1000;
// Срок оплаты: 3 дня с текущего момента
$dueTimeMs = $currentTimeMs + (86400 * 3 * 1000); 

$billData = [
    'billID' => null, // При добавлении всегда null
    'eripId' => $ERIP_ID,
    'invId' => $orderId,
    // Формат json_encode автоматически преобразует слэши, поэтому пишем без ручного экранирования:
    'dueDt' => '/Date(' . $dueTimeMs . '+0300)/',
    'addedDt' => '/Date(' . $currentTimeMs . '+0300)/',
    'payedDt' => null,
    'fullName' => $fullName,
    'mobilePhone' => $phone,
    'notifyByMobilePhone' => !empty($phone), // Шлем СМС, если указан номер
    'email' => $email,
    'notifyByEMail' => !empty($email),
    'fullAddress' => $address,
    'amt' => $amount,
    'curr' => 'BYN',
    'statusEnum' => 0,
    'info' => mb_substr($infoText, 0, 200), // обрезаем на всякий случай
    'products' => $productsPayload
];

$billRes = sendRequest($API_URL . '/Invoicing/Bill', $billData, $authCookies);

// -------------------------------------------------------------
// ЭТАП 3: Завершение сессии (обязательно)
// -------------------------------------------------------------
sendRequest($API_URL . '/Security/LogOut', null, $authCookies);

// -------------------------------------------------------------
// Обработка результата
// -------------------------------------------------------------
if (isset($billRes['body']['status']) && $billRes['body']['status'] === 0) {
    $createdBillId = $billRes['body']['billID'];
    
    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'billID' => $createdBillId,
        'message' => 'Invoice successfully created.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to build invoice',
        'api_response' => $billRes['body'],
        'api_raw' => $billRes['raw_body']
    ]);
}
