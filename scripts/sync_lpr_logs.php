<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
$plnDb = new PDO('sqlite:' . $config['db_path']);
$plnDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$plnDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$tableExists = (bool)$plnDb
    ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='lpr_events'")
    ->fetchColumn();
if (!$tableExists) {
    echo json_encode([
        'inserted' => 0,
        'skipped_existing' => 0,
        'bad_rows' => 0,
        'total_rows' => 0,
        'note' => 'lpr_events table missing',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$events = $plnDb->query('SELECT event_id, plate, is_unknown, created_at FROM lpr_events ORDER BY id ASC')->fetchAll();

$checkStmt = $plnDb->prepare("SELECT id FROM entries WHERE input_type='plate' AND reason LIKE ? LIMIT 1");
$dedupeStmt = $plnDb->prepare(
    "SELECT id FROM entries
     WHERE input_type='plate'
       AND input_value=?
       AND ABS(strftime('%s', created_at) - strftime('%s', ?)) <= 2
     LIMIT 1"
);
$insertStmt = $plnDb->prepare('INSERT INTO entries(input_type,input_value,allowed,zone,reason,created_at) VALUES (?,?,?,?,?,?)');

$ins = 0;
$skip = 0;
$bad = 0;

foreach ($events as $event) {
    $eventId = trim((string)($event['event_id'] ?? ''));
    $plate = strtoupper(trim((string)($event['plate'] ?? '')));
    $tsRaw = (string)($event['created_at'] ?? '');
    $isUnknown = ((int)($event['is_unknown'] ?? 0)) === 1 || in_array($plate, ['UNKNOWN', 'UNKNOWN_LP', 'NOT_DETECTED', ''], true);

    if ($eventId === '') {
        $bad++;
        continue;
    }

    $reasonTag = 'LPR sync event_id=' . $eventId;
    $checkStmt->execute([$reasonTag . '%']);
    if ($checkStmt->fetch()) {
        $skip++;
        continue;
    }

    $inputValue = $isUnknown ? 'UNKNOWN' : $plate;

    // Avoid duplicate backfill when an equivalent entry already exists from live forwarding.
    $dedupeStmt->execute([$inputValue, $tsRaw]);
    if ($dedupeStmt->fetch()) {
        $skip++;
        continue;
    }

    $allowed = 0;
    $zone = null;
    $reason = $reasonTag . ($isUnknown ? ' unknown_plate' : ' imported_from_lpr_events');

    $createdAt = date(DATE_ATOM, strtotime($tsRaw) ?: time());

    $insertStmt->execute(['plate', $inputValue, $allowed, $zone, $reason, $createdAt]);
    $ins++;
}

echo json_encode([
    'inserted' => $ins,
    'skipped_existing' => $skip,
    'bad_rows' => $bad,
    'total_rows' => count($events),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
