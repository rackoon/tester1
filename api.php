<?php
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/PlannerService.php';

$config = require __DIR__ . '/config.php';
$db = new Database($config);
$service = new PlannerService($db->pdo(), $config);

function appSetting(PDO $pdo, string $key, string $default): string
{
    try {
        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return $default;
        }
        return (string)$v;
    } catch (Throwable) {
        return $default;
    }
}

function displayConfig(PDO $pdo): array
{
    return [
        'standby_text' => appSetting($pdo, 'display_standby_text', 'Ootan andmeid...'),
        'detecting_text' => appSetting($pdo, 'display_detecting_text', 'Tuvastus kaib...'),
        'success_parking_text' => appSetting($pdo, 'display_success_parking_text', 'Suunata parklasse'),
        'success_service_text' => appSetting($pdo, 'display_success_service_text', 'Suunata Service Lobby alale'),
        'failed_text' => appSetting($pdo, 'display_failed_text', 'Sisenemine keelatud'),
    ];
}

function displayStatusFromRow(array $row): string
{
    $allowed = (int)($row['allowed'] ?? 0) === 1;
    if (!$allowed) {
        return 'failed';
    }
    return (($row['zone'] ?? '') === 'service_lobby') ? 'success_service' : 'success_parking';
}

function displayMessageFromRow(array $row, array $cfg): string
{
    $status = displayStatusFromRow($row);
    if ($status === 'success_service') {
        return (string)$cfg['success_service_text'];
    }
    if ($status === 'success_parking') {
        return (string)$cfg['success_parking_text'];
    }
    return (string)$cfg['failed_text'];
}

header('Content-Type: application/json');

$path = $_GET['action'] ?? '';
$raw = file_get_contents('php://input');
$payload = $raw ? json_decode($raw, true) : [];

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json', 'message' => 'Invalid JSON body']);
    exit;
}

switch ($path) {
    case 'plate-event':
        $result = $service->processPlate($payload['plate'] ?? '', (bool)($payload['has_reservation'] ?? false));
        echo json_encode($result);
        break;
    case 'phone-event':
        $phone = $payload['phone']
            ?? $payload['caller']
            ?? $payload['from']
            ?? $payload['caller_number']
            ?? '';
        $result = $service->processPhone((string)$phone, (bool)($payload['has_reservation'] ?? false));
        echo json_encode($result);
        break;
    case 'display-feed':
        $displayCfg = displayConfig($db->pdo());
        $stmt = $db->pdo()->query('SELECT * FROM entries ORDER BY id DESC LIMIT 20');
        $rows = $stmt->fetchAll();
        $items = [];
        foreach ($rows as $row) {
            $row['display_status'] = displayStatusFromRow($row);
            $row['display_message'] = displayMessageFromRow($row, $displayCfg);
            $items[] = $row;
        }
        echo json_encode(['items' => $items, 'config' => $displayCfg]);
        break;
    case 'display-config':
        echo json_encode(['config' => displayConfig($db->pdo())]);
        break;
    case 'display-stream':
        // SSE stream for kiosk clients: keeps connection open and pushes changes immediately.
        ignore_user_abort(true);
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $displayCfg = displayConfig($db->pdo());
        $lastId = (int)($_GET['last_id'] ?? 0);
        $sentInitial = false;

        while (!connection_aborted()) {
            if (!$sentInitial && $lastId === 0) {
                $stmt = $db->pdo()->query('SELECT * FROM entries ORDER BY id DESC LIMIT 1');
                $row = $stmt->fetch();
                if ($row) {
                    $lastId = (int)$row['id'];
                    $displayStatus = displayStatusFromRow($row);
                    $displayMessage = displayMessageFromRow($row, $displayCfg);
                    echo "event: display\n";
                    echo 'data: ' . json_encode([
                        'id' => $lastId,
                        'display_status' => $displayStatus,
                        'display_message' => $displayMessage,
                        'created_at' => $row['created_at'] ?? null,
                    ]) . "\n\n";
                    @ob_flush();
                    @flush();
                }
                $sentInitial = true;
            }

            $stmt = $db->pdo()->prepare('SELECT * FROM entries WHERE id > ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$lastId]);
            $row = $stmt->fetch();

            if ($row) {
                $lastId = (int)$row['id'];
                $displayStatus = displayStatusFromRow($row);
                $displayMessage = displayMessageFromRow($row, $displayCfg);
                echo "event: display\n";
                echo 'data: ' . json_encode([
                    'id' => $lastId,
                    'display_status' => $displayStatus,
                    'display_message' => $displayMessage,
                    'created_at' => $row['created_at'] ?? null,
                ]) . "\n\n";
            } else {
                echo "event: ping\n";
                echo 'data: ' . json_encode([
                    'ok' => true,
                    'display_status' => 'standby',
                    'display_message' => (string)$displayCfg['standby_text'],
                ]) . "\n\n";
            }

            @ob_flush();
            @flush();
            usleep(700000);
        }
        exit;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'unknown_action', 'message' => 'Unknown action']);
}
