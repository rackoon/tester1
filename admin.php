<?php
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/PlannerService.php';

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function normalizeScheduleFromPost(array $post): array
{
    $is247 = !empty($post['schedule_247']);
    if ($is247) {
        return ['ok' => true, 'value' => '24/7', 'error' => null];
    }

    $days = $post['days'] ?? [];
    if (!is_array($days)) {
        $days = [];
    }

    $cleanDays = [];
    foreach ($days as $d) {
        $n = (int)$d;
        if ($n >= 1 && $n <= 7) {
            $cleanDays[] = $n;
        }
    }
    $cleanDays = array_values(array_unique($cleanDays));
    sort($cleanDays);

    if (count($cleanDays) === 0) {
        return ['ok' => false, 'value' => null, 'error' => 'Vali vahemalt 1 paev'];
    }

    $start = trim((string)($post['start_time'] ?? ''));
    $end = trim((string)($post['end_time'] ?? ''));

    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $start)) {
        return ['ok' => false, 'value' => null, 'error' => 'Algusaeg on vale'];
    }

    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $end)) {
        return ['ok' => false, 'value' => null, 'error' => 'Lopuaeg on vale'];
    }

    return [
        'ok' => true,
        'value' => 'WEEK:' . implode(',', $cleanDays) . '|TIME:' . $start . '-' . $end,
        'error' => null,
    ];
}

function formatScheduleLabel(string $schedule): string
{
    $s = trim($schedule);
    if ($s === '24/7') {
        return '24/7';
    }

    if (preg_match('/^WEEK:([1-7](?:,[1-7])*)\|TIME:(\d{2}:\d{2})-(\d{2}:\d{2})$/', $s, $m)) {
        $daysMap = [
            '1' => 'E',
            '2' => 'T',
            '3' => 'K',
            '4' => 'N',
            '5' => 'R',
            '6' => 'L',
            '7' => 'P',
        ];

        $labels = [];
        foreach (explode(',', $m[1]) as $d) {
            $labels[] = $daysMap[$d] ?? $d;
        }

        return implode(',', $labels) . ' ' . $m[2] . '-' . $m[3];
    }

    return $s;
}

function isScheduleFormatValid(string $schedule): bool
{
    $s = trim(mb_strtoupper($schedule));
    if ($s === '24/7') {
        return true;
    }

    if (preg_match('/^WEEK:([1-7](?:,[1-7])*)\|TIME:(\d{2}:\d{2})-(\d{2}:\d{2})$/', $s)) {
        return true;
    }

    if (preg_match('/^([EMTRLNPK]-[EMTRLNPK])\s+(\d{1,2})-(\d{1,2})$/u', $s)) {
        return true;
    }

    return false;
}

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return $default;
        }
        return (string)$val;
    } catch (Throwable) {
        return $default;
    }
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO app_settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $stmt->execute([$key, $value]);
}

$config = require __DIR__ . '/config.php';
$db = new Database($config);
$auth = new Auth($db->pdo());

$flashError = null;
$flashSuccess = null;
$testCaseResult = null;

if (isset($_POST['login'])) {
    $ok = $auth->login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if (!$ok) {
        $flashError = 'Vale kasutajanimi voi parool';
    }
}

if (isset($_GET['logout'])) {
    $auth->logout();
    header('Location: admin.php');
    exit;
}

$user = $auth->user();
if (!$user):
?>
<!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8">
  <title>Planner</title>
  <link rel="icon" type="image/svg+xml" href="logo.svg">
  <style>
    *{box-sizing:border-box}
    body{
      font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
      margin:0;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#f6f8fb;
      padding:20px;
    }
    .card{
      width:100%;
      max-width:420px;
      padding:20px;
      border:1px solid #dbe2ea;
      border-radius:12px;
      background:#fff;
    }
    .brand{display:flex;align-items:center;gap:10px;margin-bottom:8px}
    .brand img{width:51px;height:51px;display:block}
    form{display:grid;gap:10px}
    input,button{width:100%;padding:10px}
    .err{color:#b00020;margin:8px 0}
  </style>
</head>
<body>
<div class="card">
  <div class="brand">
    <img src="logo.svg" alt="Planner logo">
    <h1>Planner</h1>
  </div>
  <?php if (!empty($flashError)): ?><p class="err"><?= h($flashError) ?></p><?php endif; ?>
  <form method="post">
    <input name="username" placeholder="Kasutajanimi" required>
    <input name="password" type="password" placeholder="Parool" required>
    <button name="login" value="1">Logi sisse</button>
  </form>
</div>
</body>
</html>
<?php
exit;
endif;

if (isset($_POST['add_rule'])) {
    $auth->requireRole(['admin', 'operator']);
    $subjectType = (string)($_POST['subject_type'] ?? 'plate');
    $subjectValue = strtoupper(trim((string)($_POST['subject_value'] ?? '')));
    $zone = (string)($_POST['zone'] ?? 'parking');

    if (!in_array($subjectType, ['plate', 'phone'], true)) {
        $flashError = 'Vigane sisendi tyyp';
    } elseif ($subjectValue === '') {
        $flashError = 'Subjekti vaartus on puudu';
    } elseif (!in_array($zone, ['parking', 'service_lobby'], true)) {
        $flashError = 'Vigane tsoon';
    } else {
        $sch = normalizeScheduleFromPost($_POST);
        if (!$sch['ok']) {
            $flashError = $sch['error'];
        } else {
            try {
                $stmt = $db->pdo()->prepare('INSERT INTO access_rules(subject_type,subject_value,schedule,zone,source,created_at) VALUES (?,?,?,?,?,?)');
                $stmt->execute([
                    $subjectType,
                    $subjectValue,
                    $sch['value'],
                    $zone,
                    'local',
                    date(DATE_ATOM),
                ]);
                $flashSuccess = 'Ligipaasu luba salvestatud';
            } catch (Throwable $e) {
                $flashError = 'Salvestamine ebaonnestus: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_POST['add_exception'])) {
    $auth->requireRole(['admin', 'operator']);
    $name = trim((string)($_POST['exception_name'] ?? ''));
    $inputType = (string)($_POST['exception_input_type'] ?? 'plate');
    $zone = (string)($_POST['exception_zone'] ?? 'parking');
    $schedule = trim((string)($_POST['exception_schedule'] ?? ''));
    $enabled = !empty($_POST['exception_enabled']) ? 1 : 0;

    if ($name === '') {
        $flashError = 'Erandi nimi on puudu';
    } elseif (!in_array($inputType, ['plate', 'phone'], true)) {
        $flashError = 'Erandi sisendi tyyp on vigane';
    } elseif (!in_array($zone, ['parking', 'service_lobby'], true)) {
        $flashError = 'Erandi tsoon on vigane';
    } elseif (!isScheduleFormatValid($schedule)) {
        $flashError = 'Erandi ajagraafik on vigane';
    } else {
        try {
            $now = date(DATE_ATOM);
            $stmt = $db->pdo()->prepare(
                "INSERT INTO access_exceptions(name,input_type,target,schedule,zone,enabled,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$name, $inputType, 'no_permit', strtoupper($schedule), $zone, $enabled, $now, $now]);
            $flashSuccess = 'Ajapohine erand lisatud';
        } catch (Throwable $e) {
            $flashError = 'Erandi lisamine ebaonnestus: ' . $e->getMessage();
        }
    }
}

if (isset($_POST['save_exception'])) {
    $auth->requireRole(['admin', 'operator']);
    $id = (int)($_POST['exception_id'] ?? 0);
    $name = trim((string)($_POST['exception_name'] ?? ''));
    $inputType = (string)($_POST['exception_input_type'] ?? 'plate');
    $zone = (string)($_POST['exception_zone'] ?? 'parking');
    $schedule = trim((string)($_POST['exception_schedule'] ?? ''));
    $enabled = !empty($_POST['exception_enabled']) ? 1 : 0;

    if ($id <= 0) {
        $flashError = 'Erandi ID on vigane';
    } elseif ($name === '') {
        $flashError = 'Erandi nimi on puudu';
    } elseif (!in_array($inputType, ['plate', 'phone'], true)) {
        $flashError = 'Erandi sisendi tyyp on vigane';
    } elseif (!in_array($zone, ['parking', 'service_lobby'], true)) {
        $flashError = 'Erandi tsoon on vigane';
    } elseif (!isScheduleFormatValid($schedule)) {
        $flashError = 'Erandi ajagraafik on vigane';
    } else {
        try {
            $stmt = $db->pdo()->prepare(
                "UPDATE access_exceptions
                 SET name=?, input_type=?, schedule=?, zone=?, enabled=?, updated_at=?
                 WHERE id=?"
            );
            $stmt->execute([$name, $inputType, strtoupper($schedule), $zone, $enabled, date(DATE_ATOM), $id]);
            $flashSuccess = 'Ajapohine erand uuendatud';
        } catch (Throwable $e) {
            $flashError = 'Erandi uuendamine ebaonnestus: ' . $e->getMessage();
        }
    }
}

if (isset($_POST['delete_exception'])) {
    $auth->requireRole(['admin', 'operator']);
    $id = (int)($_POST['exception_id'] ?? 0);
    if ($id <= 0) {
        $flashError = 'Erandi ID on vigane';
    } else {
        try {
            $stmt = $db->pdo()->prepare('DELETE FROM access_exceptions WHERE id = ?');
            $stmt->execute([$id]);
            $flashSuccess = 'Ajapohine erand kustutatud';
        } catch (Throwable $e) {
            $flashError = 'Erandi kustutamine ebaonnestus: ' . $e->getMessage();
        }
    }
}

if (isset($_POST['save_shelly'])) {
    $auth->requireRole(['admin']);
    $baseUrl = trim((string)($_POST['shelly_base_url'] ?? ''));
    $username = trim((string)($_POST['shelly_username'] ?? ''));
    $password = trim((string)($_POST['shelly_password'] ?? ''));
    $mode = strtolower(trim((string)($_POST['shelly_mode'] ?? 'auto')));
    $switchId = trim((string)($_POST['shelly_switch_id'] ?? '0'));
    $toggleAfter = trim((string)($_POST['shelly_toggle_after'] ?? '1'));

    try {
        if (!in_array($mode, ['auto', 'rpc', 'relay'], true)) {
            throw new RuntimeException('Shelly mode peab olema auto/rpc/relay');
        }
        if (!preg_match('/^\d+$/', $switchId)) {
            throw new RuntimeException('Shelly switch id peab olema taisarv');
        }
        if (!preg_match('/^\d+$/', $toggleAfter)) {
            throw new RuntimeException('Shelly toggle_after peab olema taisarv sekundites');
        }
        setSetting($db->pdo(), 'shelly_base_url', $baseUrl);
        setSetting($db->pdo(), 'shelly_username', $username);
        setSetting($db->pdo(), 'shelly_mode', $mode);
        setSetting($db->pdo(), 'shelly_switch_id', $switchId);
        setSetting($db->pdo(), 'shelly_toggle_after', $toggleAfter);
        if ($password !== '') {
            setSetting($db->pdo(), 'shelly_password', $password);
        }
        $flashSuccess = 'Shelly seaded salvestatud';
    } catch (Throwable $e) {
        $flashError = 'Shelly seadete salvestamine ebaonnestus: ' . $e->getMessage();
    }
}

if (isset($_POST['save_display_monitor'])) {
    $auth->requireRole(['admin']);
    $standbyText = trim((string)($_POST['display_standby_text'] ?? 'Ootan andmeid...'));
    $detectingText = trim((string)($_POST['display_detecting_text'] ?? 'Tuvastus kaib...'));
    $successParkingText = trim((string)($_POST['display_success_parking_text'] ?? 'Suunata parklasse'));
    $successServiceText = trim((string)($_POST['display_success_service_text'] ?? 'Suunata Service Lobby alale'));
    $failedText = trim((string)($_POST['display_failed_text'] ?? 'Sisenemine keelatud'));

    if ($standbyText === '' || $detectingText === '' || $successParkingText === '' || $successServiceText === '' || $failedText === '') {
        $flashError = 'Koik Android monitori tekstid peavad olema taidetud';
    } else {
        try {
            setSetting($db->pdo(), 'display_standby_text', $standbyText);
            setSetting($db->pdo(), 'display_detecting_text', $detectingText);
            setSetting($db->pdo(), 'display_success_parking_text', $successParkingText);
            setSetting($db->pdo(), 'display_success_service_text', $successServiceText);
            setSetting($db->pdo(), 'display_failed_text', $failedText);
            $flashSuccess = 'Android monitori seaded salvestatud';
        } catch (Throwable $e) {
            $flashError = 'Android monitori seadete salvestamine ebaonnestus: ' . $e->getMessage();
        }
    }
}

if (isset($_POST['save_sip_agent'])) {
    $auth->requireRole(['admin']);
    $enabled = !empty($_POST['sip_agent_enabled']) ? '1' : '0';
    $apiUrl = trim((string)($_POST['planner_api_url'] ?? 'https://one.crebit.eu/pln/api.php'));
    $sipUser = trim((string)($_POST['sip_user'] ?? ''));
    $sipPass = trim((string)($_POST['sip_password'] ?? ''));
    $sipDomain = trim((string)($_POST['sip_domain'] ?? ''));
    $sipTransport = trim((string)($_POST['sip_transport'] ?? 'udp'));
    $sipDisplay = trim((string)($_POST['sip_display_name'] ?? ''));
    $sipOutbound = trim((string)($_POST['sip_outbound'] ?? ''));
    $sipRegint = trim((string)($_POST['sip_regint'] ?? '300'));

    if (!in_array($sipTransport, ['udp', 'tcp', 'tls'], true)) {
        $flashError = 'SIP transport peab olema udp/tcp/tls';
    } else {
        try {
            setSetting($db->pdo(), 'sip_agent_enabled', $enabled);
            setSetting($db->pdo(), 'planner_api_url', $apiUrl);
            setSetting($db->pdo(), 'sip_user', $sipUser);
            if ($sipPass !== '') {
                setSetting($db->pdo(), 'sip_password', $sipPass);
            }
            setSetting($db->pdo(), 'sip_domain', $sipDomain);
            setSetting($db->pdo(), 'sip_transport', $sipTransport);
            setSetting($db->pdo(), 'sip_display_name', $sipDisplay);
            setSetting($db->pdo(), 'sip_outbound', $sipOutbound);
            setSetting($db->pdo(), 'sip_regint', $sipRegint);
            $flashSuccess = 'SIP agendi seaded salvestatud';
        } catch (Throwable $e) {
            $flashError = 'SIP agendi seadete salvestamine ebaonnestus: ' . $e->getMessage();
        }
    }
}

if (isset($_POST['test_case'])) {
    $auth->requireRole(['admin']);
    $testType = (string)($_POST['test_type'] ?? 'plate');
    $testValue = trim((string)($_POST['test_value'] ?? ''));
    $hasReservation = !empty($_POST['test_has_reservation']);

    if (!in_array($testType, ['plate', 'phone'], true)) {
        $flashError = 'Test case tyyp on vigane';
    } elseif ($testValue === '') {
        $flashError = 'Test case vaartus on puudu';
    } else {
        try {
            $service = new PlannerService($db->pdo(), $config);
            if ($testType === 'phone') {
                $res = $service->processPhone($testValue, $hasReservation);
            } else {
                $res = $service->processPlate($testValue, $hasReservation);
            }
            $testCaseResult = $res;
            $flashSuccess = 'Test case toodeldud';
        } catch (Throwable $e) {
            $flashError = 'Test case ebaonnestus: ' . $e->getMessage();
        }
    }
}

if (isset($_POST['sip_agent_action'])) {
    $auth->requireRole(['admin']);
    $action = (string)($_POST['sip_agent_action'] ?? '');
    if (in_array($action, ['start', 'stop', 'restart'], true)) {
        $cmd = 'cd ' . escapeshellarg(__DIR__) . ' && ./scripts/manage_sip_agent.sh ' . escapeshellarg($action) . ' 2>&1';
        $out = shell_exec($cmd);
        if ($out === null) {
            $flashError = 'SIP agendi haldus ei ole serveris lubatud (shell_exec disabled?)';
        } else {
            $flashSuccess = 'SIP agent: ' . trim($out);
        }
    }
}

if (isset($_POST['add_user'])) {
    $auth->requireRole(['admin']);
    try {
        $stmt = $db->pdo()->prepare('INSERT INTO users(username,password_hash,role,created_at) VALUES (?,?,?,?)');
        $stmt->execute([
            $_POST['username'],
            password_hash($_POST['password'], PASSWORD_DEFAULT),
            $_POST['role'],
            date(DATE_ATOM),
        ]);
        $flashSuccess = 'Kasutaja lisatud';
    } catch (Throwable $e) {
        $flashError = 'Kasutaja lisamine ebaonnestus: ' . $e->getMessage();
    }
}

$tab = (string)($_GET['tab'] ?? 'dashboard');
$allowedTabs = ['dashboard', 'users', 'permits', 'settings', 'logs'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'dashboard';
}

$rules = $db->pdo()->query('SELECT * FROM access_rules ORDER BY id DESC LIMIT 50')->fetchAll();
$exceptions = $db->pdo()->query("SELECT * FROM access_exceptions ORDER BY id DESC")->fetchAll();
$entries = $db->pdo()->query('SELECT * FROM entries ORDER BY id DESC LIMIT 20')->fetchAll();
$users = $db->pdo()->query('SELECT id,username,role,created_at FROM users ORDER BY id DESC LIMIT 50')->fetchAll();

$logsPerPage = 50;
$logsPage = max(1, (int)($_GET['page'] ?? 1));
$logsTotal = (int)$db->pdo()->query('SELECT COUNT(*) FROM entries')->fetchColumn();
$logsTotalPages = max(1, (int)ceil($logsTotal / $logsPerPage));
if ($logsPage > $logsTotalPages) {
    $logsPage = $logsTotalPages;
}
$logsOffset = ($logsPage - 1) * $logsPerPage;
$logsStmt = $db->pdo()->prepare('SELECT * FROM entries ORDER BY id DESC LIMIT :limit OFFSET :offset');
$logsStmt->bindValue(':limit', $logsPerPage, PDO::PARAM_INT);
$logsStmt->bindValue(':offset', $logsOffset, PDO::PARAM_INT);
$logsStmt->execute();
$logEntries = $logsStmt->fetchAll();

$stats = [
    'rules' => (int)$db->pdo()->query('SELECT COUNT(*) FROM access_rules')->fetchColumn(),
    'users' => (int)$db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'entries' => (int)$db->pdo()->query('SELECT COUNT(*) FROM entries')->fetchColumn(),
];

$shellyDefault = $config['shelly'] ?? [];
$shellyBaseUrl = getSetting($db->pdo(), 'shelly_base_url', (string)($shellyDefault['base_url'] ?? ''));
$shellyUsername = getSetting($db->pdo(), 'shelly_username', (string)($shellyDefault['username'] ?? ''));
$shellyPassword = getSetting($db->pdo(), 'shelly_password', (string)($shellyDefault['password'] ?? ''));
$shellyMode = getSetting($db->pdo(), 'shelly_mode', 'auto');
$shellySwitchId = getSetting($db->pdo(), 'shelly_switch_id', '0');
$shellyToggleAfter = getSetting($db->pdo(), 'shelly_toggle_after', '1');
// Android display monitor texts.
$displayStandbyText = getSetting($db->pdo(), 'display_standby_text', 'Ootan andmeid...');
$displayDetectingText = getSetting($db->pdo(), 'display_detecting_text', 'Tuvastus kaib...');
$displaySuccessParkingText = getSetting($db->pdo(), 'display_success_parking_text', 'Suunata parklasse');
$displaySuccessServiceText = getSetting($db->pdo(), 'display_success_service_text', 'Suunata Service Lobby alale');
$displayFailedText = getSetting($db->pdo(), 'display_failed_text', 'Sisenemine keelatud');

$sipAgentEnabled = getSetting($db->pdo(), 'sip_agent_enabled', '0') === '1';
$plannerApiUrl = getSetting($db->pdo(), 'planner_api_url', 'https://one.crebit.eu/pln/api.php');
$sipUser = getSetting($db->pdo(), 'sip_user', '');
$sipPass = getSetting($db->pdo(), 'sip_password', '');
$sipDomain = getSetting($db->pdo(), 'sip_domain', '');
$sipTransport = getSetting($db->pdo(), 'sip_transport', 'udp');
$sipDisplayName = getSetting($db->pdo(), 'sip_display_name', '');
$sipOutbound = getSetting($db->pdo(), 'sip_outbound', '');
$sipRegint = getSetting($db->pdo(), 'sip_regint', '300');
$sipAgentStatusRaw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && ./scripts/manage_sip_agent.sh status 2>/dev/null');
$sipAgentStatus = trim((string)$sipAgentStatusRaw);
if ($sipAgentStatus === '') {
    $sipAgentStatus = 'unknown';
}
?>
<!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8">
  <title>Planner</title>
  <link rel="icon" type="image/svg+xml" href="logo.svg">
  <style>
    :root{--bg:#f6f8fb;--card:#fff;--txt:#0f172a;--muted:#475569;--ok:#065f46;--err:#b91c1c;--line:#dbe2ea;--pri:#0f766e}
    body{margin:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
    .wrap{max-width:1200px;margin:24px auto;padding:0 16px 40px}
    .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:51px;height:51px;display:block}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}
    .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}
    h1,h2{margin:0 0 12px}
    .muted{color:var(--muted)}
    label{display:block;font-size:13px;color:var(--muted);margin:8px 0 4px}
    input,select,button{width:100%;box-sizing:border-box;padding:9px;border:1px solid #cbd5e1;border-radius:10px}
    button{background:var(--pri);color:#fff;border:0;cursor:pointer;margin-top:10px}
    .flash-ok,.flash-err{padding:10px 12px;border-radius:10px;margin-bottom:12px}
    .flash-ok{background:#ecfdf5;color:var(--ok);border:1px solid #a7f3d0}
    .flash-err{background:#fef2f2;color:var(--err);border:1px solid #fecaca}
    .days{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-top:6px}
    .day{display:flex;justify-content:center;align-items:center;border:1px solid #cbd5e1;border-radius:10px;padding:8px 0;background:#fff}
    .day input{width:auto;margin-right:6px}
    .time-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}
    ul{margin:8px 0 0;padding-left:18px}
    li{margin:4px 0}
    .hint{font-size:12px;color:var(--muted);margin-top:6px}
    .pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px}
    .tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
    .tab{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none}
    .tab.active{background:#0f766e;color:#fff;border-color:#0f766e}
    .kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
    .kpi .card h2{font-size:14px;color:var(--muted);margin-bottom:8px}
    .kpi .value{font-size:28px;font-weight:700}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand">
      <img src="logo.svg" alt="Planner logo">
      <h1>Planner</h1>
    </div>
    <p>Sisse logitud: <strong><?= h($user['username']) ?></strong> (<?= h($user['role']) ?>) - <a href="?logout=1">Logi valja</a></p>
  </div>

  <?php if ($flashSuccess): ?><div class="flash-ok"><?= h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError): ?><div class="flash-err"><?= h($flashError) ?></div><?php endif; ?>

  <nav class="tabs">
    <a class="tab <?= $tab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard">Dashboard</a>
    <a class="tab <?= $tab === 'users' ? 'active' : '' ?>" href="?tab=users">Kasutajad</a>
    <a class="tab <?= $tab === 'permits' ? 'active' : '' ?>" href="?tab=permits">Load</a>
    <a class="tab <?= $tab === 'settings' ? 'active' : '' ?>" href="?tab=settings">Seaded</a>
    <a class="tab <?= $tab === 'logs' ? 'active' : '' ?>" href="?tab=logs">Logid</a>
  </nav>

  <?php if ($tab === 'dashboard'): ?>
  <section class="kpi">
    <article class="card"><h2>Lube</h2><div class="value"><?= h((string)$stats['rules']) ?></div></article>
    <article class="card"><h2>Logikirjeid</h2><div class="value"><?= h((string)$stats['entries']) ?></div></article>
  </section>
  <div class="grid" style="margin-top:14px">
    <section class="card">
      <h2>Viimased load</h2>
      <ul>
      <?php foreach (array_slice($rules, 0, 10) as $r): ?>
        <li><span class="pill"><?= h($r['subject_type']) ?></span> <?= h($r['subject_value']) ?> | <?= h(formatScheduleLabel((string)$r['schedule'])) ?></li>
      <?php endforeach; ?>
      </ul>
    </section>
    <section class="card">
      <h2>Viimased sisenemised</h2>
      <ul>
      <?php foreach ($entries as $e): ?>
        <li><?= h($e['created_at']) ?> - <?= h($e['input_type']) ?>:<?= h($e['input_value']) ?> => <?= $e['allowed'] ? 'ALLOWED' : 'DENIED' ?></li>
      <?php endforeach; ?>
      </ul>
    </section>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'permits'): ?>
  <div class="grid">
    <section class="card">
      <h2>Uus ligipaasu luba</h2>
      <form method="post" id="ruleForm">
        <label>Tyyp</label>
        <select name="subject_type">
          <option value="plate">Auto nr</option>
          <option value="phone">Telefon</option>
        </select>
        <label>Vaartus</label>
        <input name="subject_value" placeholder="ABC123 voi +372..." required>
        <label>Tsoon</label>
        <select name="zone">
          <option value="parking">parking</option>
          <option value="service_lobby">service_lobby</option>
        </select>
        <label><input type="checkbox" id="schedule247" name="schedule_247" value="1" checked style="width:auto"> 24/7</label>
        <div id="customSchedule" style="display:none">
          <label>Paevad</label>
          <div class="days">
            <label class="day"><input type="checkbox" name="days[]" value="1">E</label>
            <label class="day"><input type="checkbox" name="days[]" value="2">T</label>
            <label class="day"><input type="checkbox" name="days[]" value="3">K</label>
            <label class="day"><input type="checkbox" name="days[]" value="4">N</label>
            <label class="day"><input type="checkbox" name="days[]" value="5">R</label>
            <label class="day"><input type="checkbox" name="days[]" value="6">L</label>
            <label class="day"><input type="checkbox" name="days[]" value="7">P</label>
          </div>
          <div class="time-row">
            <div><label>Algus</label><input type="time" name="start_time" value="07:00"></div>
            <div><label>Lopp</label><input type="time" name="end_time" value="19:00"></div>
          </div>
          <p class="hint">Toetatud ka yle oo vahemik (naiteks 22:00-06:00).</p>
        </div>
        <button name="add_rule" value="1">Salvesta luba</button>
      </form>
    </section>
    <section class="card">
      <h2>Load</h2>
      <ul>
      <?php foreach ($rules as $r): ?>
        <li><span class="pill"><?= h($r['subject_type']) ?></span> <?= h($r['subject_value']) ?> | <?= h(formatScheduleLabel((string)$r['schedule'])) ?> | <?= h($r['zone']) ?> | <?= h($r['source']) ?></li>
      <?php endforeach; ?>
      </ul>
    </section>

    <section class="card">
      <h2>Ajapohised erandid</h2>
      <form method="post">
        <label>Erandi nimi</label>
        <input name="exception_name" placeholder="Naiteks: E-L paevane sissepaas ilma loata" required>
        <label>Tyyp</label>
        <select name="exception_input_type">
          <option value="plate">Auto nr</option>
          <option value="phone">Telefon</option>
        </select>
        <label>Ajagraafik</label>
        <input name="exception_schedule" placeholder="24/7 voi WEEK:1,2,3,4,5,6|TIME:08:00-19:00" required>
        <label>Tsoon</label>
        <select name="exception_zone">
          <option value="parking">parking</option>
          <option value="service_lobby">service_lobby</option>
        </select>
        <label><input type="checkbox" name="exception_enabled" value="1" style="width:auto" checked> Aktiivne</label>
        <button name="add_exception" value="1">Lisa erand</button>
      </form>
      <hr style="margin:14px 0;border:0;border-top:1px solid #dbe2ea">
      <?php foreach ($exceptions as $ex): ?>
      <form method="post" style="padding:10px;border:1px solid #dbe2ea;border-radius:10px;margin-bottom:10px">
        <input type="hidden" name="exception_id" value="<?= h((string)$ex['id']) ?>">
        <label>Nimi</label>
        <input name="exception_name" value="<?= h((string)$ex['name']) ?>" required>
        <label>Tyyp</label>
        <select name="exception_input_type">
          <option value="plate" <?= $ex['input_type'] === 'plate' ? 'selected' : '' ?>>Auto nr</option>
          <option value="phone" <?= $ex['input_type'] === 'phone' ? 'selected' : '' ?>>Telefon</option>
        </select>
        <label>Ajagraafik</label>
        <input name="exception_schedule" value="<?= h((string)$ex['schedule']) ?>" required>
        <label>Tsoon</label>
        <select name="exception_zone">
          <option value="parking" <?= $ex['zone'] === 'parking' ? 'selected' : '' ?>>parking</option>
          <option value="service_lobby" <?= $ex['zone'] === 'service_lobby' ? 'selected' : '' ?>>service_lobby</option>
        </select>
        <label><input type="checkbox" name="exception_enabled" value="1" style="width:auto" <?= ((int)$ex['enabled'] === 1) ? 'checked' : '' ?>> Aktiivne</label>
        <p class="hint">Siht: ilma loata (<?= h((string)$ex['target']) ?>) | Loodud: <?= h((string)$ex['created_at']) ?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button style="width:auto" name="save_exception" value="1">Salvesta muudatus</button>
          <button style="width:auto;background:#b91c1c" name="delete_exception" value="1" onclick="return confirm('Kustutada erand?')">Kustuta</button>
        </div>
      </form>
      <?php endforeach; ?>
    </section>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'settings'): ?>
  <div class="grid">
    <section class="card">
      <h2>Test case</h2>
      <form method="post">
        <label>Tyyp</label>
        <select name="test_type">
          <option value="plate">Auto nr</option>
          <option value="phone">Telefon</option>
        </select>
        <label>Vaartus</label>
        <input name="test_value" placeholder="ABC123 voi +3725550001" required>
        <label><input type="checkbox" name="test_has_reservation" value="1" style="width:auto"> has_reservation = true</label>
        <button name="test_case" value="1">Kaivita test case</button>
      </form>
      <?php if (is_array($testCaseResult)): ?>
      <p class="hint">Vastus:</p>
      <pre style="white-space:pre-wrap;background:#0b1020;color:#dbeafe;padding:10px;border-radius:10px;overflow:auto;"><?= h(json_encode($testCaseResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Android monitor</h2>
      <form method="post">
        <label>Stand-by tekst</label>
        <input name="display_standby_text" value="<?= h($displayStandbyText) ?>" required>
        <label>Tuvastuse ajal tekst</label>
        <input name="display_detecting_text" value="<?= h($displayDetectingText) ?>" required>
        <label>Onnestunud tuvastus (parkla)</label>
        <input name="display_success_parking_text" value="<?= h($displaySuccessParkingText) ?>" required>
        <label>Onnestunud tuvastus (service lobby)</label>
        <input name="display_success_service_text" value="<?= h($displaySuccessServiceText) ?>" required>
        <label>Ebaonnestunud tuvastus</label>
        <input name="display_failed_text" value="<?= h($displayFailedText) ?>" required>
        <button name="save_display_monitor" value="1">Salvesta Android monitori seaded</button>
      </form>
      <p class="hint">Android monitor loeb neid tekste API endpointist <code>action=display-config</code>.</p>
    </section>

    <section class="card">
      <h2>Shelly relee seadistus</h2>
      <form method="post">
        <label>Shelly baas URL</label>
        <input name="shelly_base_url" value="<?= h($shellyBaseUrl) ?>" placeholder="http://shelly-ip" required>
        <label>Shelly mode</label>
        <select name="shelly_mode">
          <option value="auto" <?= $shellyMode === 'auto' ? 'selected' : '' ?>>auto (proovi RPC, fallback relay)</option>
          <option value="rpc" <?= $shellyMode === 'rpc' ? 'selected' : '' ?>>rpc (Shelly Pro/Gen2 soovituslik)</option>
          <option value="relay" <?= $shellyMode === 'relay' ? 'selected' : '' ?>>relay (legacy Gen1)</option>
        </select>
        <label>Switch ID / Relay nr</label>
        <input name="shelly_switch_id" value="<?= h($shellySwitchId) ?>" placeholder="0" required>
        <label>toggle_after / timer (sek)</label>
        <input name="shelly_toggle_after" value="<?= h($shellyToggleAfter) ?>" placeholder="1" required>
        <label>Kasutaja (valikuline)</label>
        <input name="shelly_username" value="<?= h($shellyUsername) ?>" placeholder="admin">
        <label>Parool (valikuline)</label>
        <input name="shelly_password" type="password" value="" placeholder="Jata tuhjaks, et vana jaaks alles">
        <p class="hint">Shelly Pro 2PM jaoks kasuta tavaliselt: baas URL `http://SEADME_IP`, mode `rpc`, switch id `0` voi `1`.</p>
        <p class="hint">Kui paroolivaili tuhjaks jaatad, jaab eelmine parool alles.</p>
        <button name="save_shelly" value="1">Salvesta Shelly seaded</button>
      </form>
    </section>

    <section class="card">
      <h2>SIP agent</h2>
      <p class="hint">Staatus: <strong><?= h($sipAgentStatus) ?></strong></p>
      <form method="post">
        <label><input type="checkbox" name="sip_agent_enabled" value="1" style="width:auto" <?= $sipAgentEnabled ? 'checked' : '' ?>> SIP agent lubatud</label>
        <label>Planner API URL (phone-event)</label>
        <input name="planner_api_url" value="<?= h($plannerApiUrl) ?>" placeholder="https://one.crebit.eu/pln/api.php" required>
        <label>SIP kasutaja</label>
        <input name="sip_user" value="<?= h($sipUser) ?>" placeholder="1001" required>
        <label>SIP parool</label>
        <input name="sip_password" type="password" value="" placeholder="Jata tuhjaks, et vana jaaks alles">
        <label>SIP domain/registrar host</label>
        <input name="sip_domain" value="<?= h($sipDomain) ?>" placeholder="sip.example.com" required>
        <label>SIP transport</label>
        <select name="sip_transport">
          <option value="udp" <?= $sipTransport === 'udp' ? 'selected' : '' ?>>udp</option>
          <option value="tcp" <?= $sipTransport === 'tcp' ? 'selected' : '' ?>>tcp</option>
          <option value="tls" <?= $sipTransport === 'tls' ? 'selected' : '' ?>>tls</option>
        </select>
        <label>Display name (valikuline)</label>
        <input name="sip_display_name" value="<?= h($sipDisplayName) ?>" placeholder="Planner Gate">
        <label>Outbound proxy host (valikuline)</label>
        <input name="sip_outbound" value="<?= h($sipOutbound) ?>" placeholder="proxy.example.com">
        <label>Registreerimisintervall (sek)</label>
        <input name="sip_regint" value="<?= h($sipRegint) ?>" placeholder="300">
        <button name="save_sip_agent" value="1">Salvesta SIP agendi seaded</button>
      </form>
      <form method="post" style="margin-top:10px">
        <button name="sip_agent_action" value="start">Kaivita SIP agent</button>
        <button name="sip_agent_action" value="restart">Restart SIP agent</button>
        <button name="sip_agent_action" value="stop">Peata SIP agent</button>
      </form>
    </section>

  </div>
  <?php endif; ?>

  <?php if ($tab === 'users'): ?>
  <div class="grid">
    <?php if ($user['role'] === 'admin'): ?>
    <section class="card">
      <h2>Lisa kasutaja</h2>
      <form method="post">
        <label>Kasutajanimi</label><input name="username" required>
        <label>Parool</label><input name="password" type="password" required>
        <label>Roll</label>
        <select name="role">
          <option value="viewer">viewer</option>
          <option value="operator">operator</option>
          <option value="admin">admin</option>
        </select>
        <button name="add_user" value="1">Lisa kasutaja</button>
      </form>
    </section>
    <?php endif; ?>
    <section class="card">
      <h2>Kasutajate nimekiri</h2>
      <ul>
      <?php foreach ($users as $u): ?>
        <li><?= h($u['username']) ?> | <?= h($u['role']) ?> | <?= h($u['created_at']) ?></li>
      <?php endforeach; ?>
      </ul>
    </section>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'logs'): ?>
  <section class="card">
    <h2>Sisenemiste logi</h2>
    <p class="muted">Leht <?= h((string)$logsPage) ?> / <?= h((string)$logsTotalPages) ?>, kokku <?= h((string)$logsTotal) ?> kirjet</p>
    <ul>
    <?php foreach ($logEntries as $e): ?>
      <li><?= h($e['created_at']) ?> - <?= h($e['input_type']) ?>:<?= h($e['input_value']) ?> => <?= $e['allowed'] ? 'ALLOWED' : 'DENIED' ?> (<?= h($e['reason']) ?>)</li>
    <?php endforeach; ?>
    </ul>
    <div style="display:flex;gap:8px;margin-top:12px">
      <?php if ($logsPage > 1): ?>
        <a class="tab" href="?tab=logs&page=<?= h((string)($logsPage - 1)) ?>">Eelmine</a>
      <?php endif; ?>
      <?php if ($logsPage < $logsTotalPages): ?>
        <a class="tab" href="?tab=logs&page=<?= h((string)($logsPage + 1)) ?>">Jargmine</a>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<script>
(function(){
  var check = document.getElementById('schedule247');
  var box = document.getElementById('customSchedule');
  if (!check || !box) return;
  function sync(){
    box.style.display = check.checked ? 'none' : 'block';
  }
  check.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
