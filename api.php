<?php
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/PlannerService.php';

$config = require __DIR__ . '/config.php';
$db = new Database($config);
$service = new PlannerService($db->pdo(), $config);

header('Content-Type: application/json');

$path = $_GET['action'] ?? '';
$raw = file_get_contents('php://input');
$payload = $raw ? json_decode($raw, true) : [];

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Vale JSON']);
    exit;
}

switch ($path) {
    case 'plate-event':
        $result = $service->processPlate($payload['plate'] ?? '', (bool)($payload['has_reservation'] ?? false));
        echo json_encode($result);
        break;
    case 'phone-event':
        $result = $service->processPhone($payload['phone'] ?? '', (bool)($payload['has_reservation'] ?? false));
        echo json_encode($result);
        break;
    case 'display-feed':
        $stmt = $db->pdo()->query('SELECT * FROM entries ORDER BY id DESC LIMIT 20');
        echo json_encode(['items' => $stmt->fetchAll()]);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Tundmatu action']);
}
