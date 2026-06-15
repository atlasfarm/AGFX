<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$contactsFile = __DIR__ . '/contacts.json';
$contacts = file_exists($contactsFile) ? json_decode(file_get_contents($contactsFile), true) : [];

if (!is_array($contacts)) {
    $contacts = [];
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'count' => count($contacts),
        'contacts' => array_values($contacts)
    ]);
    exit;
}

?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Senarai Contact</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #17212b;
        }

        main {
            width: min(980px, calc(100vw - 32px));
            margin: 32px auto;
        }

        h1 {
            margin: 0 0 16px;
            font-size: 26px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border: 1px solid #d8e0ea;
        }

        th,
        td {
            padding: 10px;
            border-bottom: 1px solid #e8edf3;
            text-align: left;
            font-size: 14px;
            vertical-align: top;
        }

        th {
            background: #eef3f8;
        }

        .empty {
            background: #fff;
            border: 1px solid #d8e0ea;
            padding: 16px;
        }
    </style>
</head>
<body>
<main>
    <h1>Senarai Contact (<?php echo count($contacts); ?>)</h1>

    <?php if (count($contacts) === 0): ?>
        <div class="empty">Belum ada contact tersimpan.</div>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Nama</th>
                <th>Telefon</th>
                <th>Telegram ID</th>
                <th>Username</th>
                <th>Tarikh Simpan</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($contacts as $contact): ?>
                <tr>
                    <td><?php echo htmlspecialchars(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))); ?></td>
                    <td><?php echo htmlspecialchars($contact['phone'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($contact['telegram_id'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars(($contact['username'] ?? '') !== '' ? '@' . $contact['username'] : '-'); ?></td>
                    <td><?php echo htmlspecialchars($contact['saved_at'] ?? '-'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
