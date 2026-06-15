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

if ($nama === '' || $ic === '' || $telefon === '' || $negeri === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Semua medan diperlukan.']);
    exit;
}

$phoneDigits = normalizePhone($telefon);
$contactsFile = __DIR__ . '/contacts.json';
$contacts = file_exists($contactsFile) ? json_decode(file_get_contents($contactsFile), true) : [];
if (!is_array($contacts)) {
    $contacts = [];
}

$userContact = null;
foreach ($contacts as $contact) {
    if (normalizePhone($contact['phone'] ?? '') === $phoneDigits) {
        $userContact = $contact;
        break;
    }
}

if (!$userContact || empty($userContact['chat_id'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Tiada contact Telegram dijumpai untuk nombor telefon ini. Sila kongsi nombor anda dengan bot Telegram terlebih dahulu.']);
    exit;
}

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
if ($token === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Telegram bot token belum diset.']);
    exit;
}

$otp = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
$otpFile = __DIR__ . '/otps.json';
$otps = file_exists($otpFile) ? json_decode(file_get_contents($otpFile), true) : [];
if (!is_array($otps)) {
    $otps = [];
}

$otps[$phoneDigits] = [
    'otp' => $otp,
    'nama' => $nama,
    'ic' => $ic,
    'telefon' => $telefon,
    'negeri' => $negeri,
    'requested_at' => date('c'),
    'chat_id' => $userContact['chat_id']
];
file_put_contents($otpFile, json_encode($otps, JSON_PRETTY_PRINT));

$message = "Kod OTP anda adalah: $otp\n\nSila masukkan kod ini di laman web untuk meneruskan proses semakan.";
$url = "https://api.telegram.org/bot$token/sendMessage";
$options = [
    'http' => [
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode([
            'chat_id' => $userContact['chat_id'],
            'text' => $message
        ])
    ]
];

$response = @file_get_contents($url, false, stream_context_create($options));
if ($response === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal menghantar OTP ke Telegram.']);
    exit;
}

$result = json_decode($response, true);
if (!isset($result['ok']) || $result['ok'] !== true) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Telegram mengembalikan ralat: ' . ($result['description'] ?? 'unknown')]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'OTP telah dihantar ke Telegram anda.']);

?>
