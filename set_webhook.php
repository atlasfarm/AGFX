<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';

if ($token === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN belum diset.']);
    exit;
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

if ($host === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Host tidak dijumpai.']);
    exit;
}

$webhookUrl = 'https://' . $host . '/bot.php';
$telegramUrl = 'https://api.telegram.org/bot' . $token . '/setWebhook';

$payload = http_build_query([
    'url' => $webhookUrl,
    'drop_pending_updates' => 'true'
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'ignore_errors' => true
    ]
]);

$response = file_get_contents($telegramUrl, false, $context);

echo $response ?: json_encode([
    'ok' => false,
    'error' => 'Gagal set webhook.',
    'webhook_url' => $webhookUrl
]);

?>
