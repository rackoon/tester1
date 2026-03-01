<?php
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/PlannerService.php';

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function supportedLanguages(): array
{
    return ['et', 'en', 'fi', 'sv', 'lv', 'lt'];
}

function loadTranslations(string $lang): array
{
    $fallback = __DIR__ . '/lang/et.php';
    $base = file_exists($fallback) ? (require $fallback) : [];
    $path = __DIR__ . '/lang/' . $lang . '.php';
    if (!file_exists($path)) {
        return $base;
    }
    $current = require $path;
    if (!is_array($current)) {
        return $base;
    }
    return array_merge($base, $current);
}

function t(string $key): string
{
    global $translations;
    return (string)($translations[$key] ?? $key);
}

function tr(string $key, array $vars = []): string
{
    $text = t($key);
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}

function decisionLabel(bool $allowed): string
{
    return $allowed ? t('decision_allowed') : t('decision_denied');
}

function reasonLabel(string $reasonRaw): string
{
    $reasonRaw = trim($reasonRaw);
    if ($reasonRaw === '') {
        return '';
    }

    if (str_starts_with($reasonRaw, 'exception_time_based|')) {
        $name = trim(substr($reasonRaw, strlen('exception_time_based|')));
        if ($name === '') {
            $name = t('reason_unnamed');
        }
        return tr('reason_exception_time_based', ['name' => $name]);
    }

    $map = [
        'permit_missing' => 'reason_permit_missing',
        'permit_outside_schedule' => 'reason_permit_outside_schedule',
        'gate_opened' => 'reason_gate_opened',
        // Backward compatibility for old Estonian reasons already in DB.
        'Luba puudub' => 'reason_permit_missing',
        'Luba ei kehti sellel ajal' => 'reason_permit_outside_schedule',
        'Värav avatud' => 'reason_gate_opened',
    ];

    if (isset($map[$reasonRaw])) {
        return t($map[$reasonRaw]);
    }

    if (str_starts_with($reasonRaw, 'Ajapohine erand: ')) {
        $name = trim(substr($reasonRaw, strlen('Ajapohine erand: ')));
        if ($name === '') {
            $name = t('reason_unnamed');
        }
        return tr('reason_exception_time_based', ['name' => $name]);
    }

    return $reasonRaw;
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
$supportedLangs = supportedLanguages();
$lang = (string)($_GET['lang'] ?? ($_COOKIE['pln_lang'] ?? 'et'));
if (!in_array($lang, $supportedLangs, true)) {
    $lang = 'et';
}
if (isset($_GET['lang']) && in_array((string)$_GET['lang'], $supportedLangs, true)) {
    setcookie('pln_lang', $lang, [
        'expires' => time() + 31536000,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}
$translations = loadTranslations($lang);

$flashError = null;
$flashSuccess = null;
$testCaseResult = null;

if (isset($_POST['login'])) {
    $ok = $auth->login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if (!$ok) {
        $flashError = t('flash_login_failed');
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
<html lang="<?= h($lang) ?>">
<head>
  <meta charset="utf-8">
  <title><?= h(t('app_title')) ?></title>
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
    <h1><?= h(t('app_title')) ?></h1>
  </div>
  <?php if (!empty($flashError)): ?><p class="err"><?= h($flashError) ?></p><?php endif; ?>
  <form method="post">
    <input name="username" placeholder="<?= h(t('username')) ?>" required>
    <input name="password" type="password" placeholder="<?= h(t('password')) ?>" required>
    <button name="login" value="1"><?= h(t('login')) ?></button>
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
        $flashError = t('flash_invalid_input_type');
    } elseif ($subjectValue === '') {
        $flashError = t('flash_missing_subject_value');
    } elseif (!in_array($zone, ['parking', 'service_lobby'], true)) {
        $flashError = t('flash_invalid_zone');
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
                $flashSuccess = t('flash_permit_saved');
            } catch (Throwable $e) {
                $flashError = tr('flash_save_failed_details', ['error' => $e->getMessage()]);
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
        $flashError = t('flash_exception_name_missing');
    } elseif (!in_array($inputType, ['plate', 'phone'], true)) {
        $flashError = t('flash_exception_input_invalid');
    } elseif (!in_array($zone, ['parking', 'service_lobby'], true)) {
        $flashError = t('flash_exception_zone_invalid');
    } elseif (!isScheduleFormatValid($schedule)) {
        $flashError = t('flash_exception_schedule_invalid');
    } else {
        try {
            $now = date(DATE_ATOM);
            $stmt = $db->pdo()->prepare(
                "INSERT INTO access_exceptions(name,input_type,target,schedule,zone,enabled,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$name, $inputType, 'no_permit', strtoupper($schedule), $zone, $enabled, $now, $now]);
            $flashSuccess = t('flash_exception_added');
        } catch (Throwable $e) {
            $flashError = tr('flash_exception_add_failed_details', ['error' => $e->getMessage()]);
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
        $flashError = t('flash_exception_id_invalid');
    } elseif ($name === '') {
        $flashError = t('flash_exception_name_missing');
    } elseif (!in_array($inputType, ['plate', 'phone'], true)) {
        $flashError = t('flash_exception_input_invalid');
    } elseif (!in_array($zone, ['parking', 'service_lobby'], true)) {
        $flashError = t('flash_exception_zone_invalid');
    } elseif (!isScheduleFormatValid($schedule)) {
        $flashError = t('flash_exception_schedule_invalid');
    } else {
        try {
            $stmt = $db->pdo()->prepare(
                "UPDATE access_exceptions
                 SET name=?, input_type=?, schedule=?, zone=?, enabled=?, updated_at=?
                 WHERE id=?"
            );
            $stmt->execute([$name, $inputType, strtoupper($schedule), $zone, $enabled, date(DATE_ATOM), $id]);
            $flashSuccess = t('flash_exception_updated');
        } catch (Throwable $e) {
            $flashError = tr('flash_exception_update_failed_details', ['error' => $e->getMessage()]);
        }
    }
}

if (isset($_POST['delete_exception'])) {
    $auth->requireRole(['admin', 'operator']);
    $id = (int)($_POST['exception_id'] ?? 0);
    if ($id <= 0) {
        $flashError = t('flash_exception_id_invalid');
    } else {
        try {
            $stmt = $db->pdo()->prepare('DELETE FROM access_exceptions WHERE id = ?');
            $stmt->execute([$id]);
            $flashSuccess = t('flash_exception_deleted');
        } catch (Throwable $e) {
            $flashError = tr('flash_exception_delete_failed_details', ['error' => $e->getMessage()]);
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
            throw new RuntimeException(t('flash_shelly_mode_invalid'));
        }
        if (!preg_match('/^\d+$/', $switchId)) {
            throw new RuntimeException(t('flash_shelly_switch_invalid'));
        }
        if (!preg_match('/^\d+$/', $toggleAfter)) {
            throw new RuntimeException(t('flash_shelly_toggle_invalid'));
        }
        setSetting($db->pdo(), 'shelly_base_url', $baseUrl);
        setSetting($db->pdo(), 'shelly_username', $username);
        setSetting($db->pdo(), 'shelly_mode', $mode);
        setSetting($db->pdo(), 'shelly_switch_id', $switchId);
        setSetting($db->pdo(), 'shelly_toggle_after', $toggleAfter);
        if ($password !== '') {
            setSetting($db->pdo(), 'shelly_password', $password);
        }
        $flashSuccess = t('flash_shelly_saved');
    } catch (Throwable $e) {
        $flashError = tr('flash_shelly_save_failed_details', ['error' => $e->getMessage()]);
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
        $flashError = t('flash_android_texts_required');
    } else {
        try {
            setSetting($db->pdo(), 'display_standby_text', $standbyText);
            setSetting($db->pdo(), 'display_detecting_text', $detectingText);
            setSetting($db->pdo(), 'display_success_parking_text', $successParkingText);
            setSetting($db->pdo(), 'display_success_service_text', $successServiceText);
            setSetting($db->pdo(), 'display_failed_text', $failedText);
            $flashSuccess = t('flash_android_saved');
        } catch (Throwable $e) {
            $flashError = tr('flash_android_save_failed_details', ['error' => $e->getMessage()]);
        }
    }
}

if (isset($_POST['save_ampron_display'])) {
    $auth->requireRole(['admin']);
    $enabled = !empty($_POST['ampron_enabled']) ? '1' : '0';
    $baseUrl = trim((string)($_POST['ampron_base_url'] ?? ''));
    $displayId = trim((string)($_POST['ampron_display_id'] ?? 'SERVICE_LOBBY'));
    $standbyLayout = trim((string)($_POST['ampron_standby_layout'] ?? 'service_lobby'));
    $standbyField = trim((string)($_POST['ampron_standby_field'] ?? 'text'));
    $standbyText = trim((string)($_POST['ampron_standby_text'] ?? 'Service Lobby'));
    $standbyExtra = trim((string)($_POST['ampron_standby_extra_query'] ?? ''));
    $plateLayout = trim((string)($_POST['ampron_plate_layout'] ?? 'vehiclenumber'));
    $plateField = trim((string)($_POST['ampron_plate_field'] ?? 'plate'));
    $plateExtra = trim((string)($_POST['ampron_plate_extra_query'] ?? ''));
    $username = trim((string)($_POST['ampron_username'] ?? ''));
    $password = trim((string)($_POST['ampron_password'] ?? ''));
    $timeout = trim((string)($_POST['ampron_timeout'] ?? '3'));

    $nameRx = '/^[A-Za-z0-9_.:-]+$/';
    $fieldRx = '/^[A-Za-z0-9_\\[\\].:-]+$/';

    try {
        if ($enabled === '1') {
            if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
                throw new RuntimeException(t('flash_ampron_base_url_invalid'));
            }
            if ($displayId === '' || !preg_match($nameRx, $displayId)) {
                throw new RuntimeException(t('flash_ampron_display_id_invalid'));
            }
            if ($standbyLayout === '' || !preg_match($nameRx, $standbyLayout)) {
                throw new RuntimeException(t('flash_ampron_standby_layout_invalid'));
            }
            if ($plateLayout === '' || !preg_match($nameRx, $plateLayout)) {
                throw new RuntimeException(t('flash_ampron_plate_layout_invalid'));
            }
            if ($standbyField === '' || !preg_match($fieldRx, $standbyField)) {
                throw new RuntimeException(t('flash_ampron_standby_field_invalid'));
            }
            if ($plateField === '' || !preg_match($fieldRx, $plateField)) {
                throw new RuntimeException(t('flash_ampron_plate_field_invalid'));
            }
            if ($standbyText === '') {
                throw new RuntimeException(t('flash_ampron_standby_text_missing'));
            }
        }
        if (!preg_match('/^\\d+$/', $timeout)) {
            throw new RuntimeException(t('flash_ampron_timeout_invalid'));
        }

        setSetting($db->pdo(), 'ampron_enabled', $enabled);
        setSetting($db->pdo(), 'ampron_base_url', $baseUrl);
        setSetting($db->pdo(), 'ampron_display_id', $displayId);
        setSetting($db->pdo(), 'ampron_standby_layout', $standbyLayout);
        setSetting($db->pdo(), 'ampron_standby_field', $standbyField);
        setSetting($db->pdo(), 'ampron_standby_text', $standbyText);
        setSetting($db->pdo(), 'ampron_standby_extra_query', $standbyExtra);
        setSetting($db->pdo(), 'ampron_plate_layout', $plateLayout);
        setSetting($db->pdo(), 'ampron_plate_field', $plateField);
        setSetting($db->pdo(), 'ampron_plate_extra_query', $plateExtra);
        setSetting($db->pdo(), 'ampron_username', $username);
        setSetting($db->pdo(), 'ampron_timeout', $timeout);
        if ($password !== '') {
            setSetting($db->pdo(), 'ampron_password', $password);
        }
        $flashSuccess = t('flash_ampron_saved');
    } catch (Throwable $e) {
        $flashError = tr('flash_ampron_save_failed_details', ['error' => $e->getMessage()]);
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
        $flashError = t('flash_sip_transport_invalid');
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
            $flashSuccess = t('flash_sip_saved');
        } catch (Throwable $e) {
            $flashError = tr('flash_sip_save_failed_details', ['error' => $e->getMessage()]);
        }
    }
}

if (isset($_POST['test_case'])) {
    $auth->requireRole(['admin']);
    $testType = (string)($_POST['test_type'] ?? 'plate');
    $testValue = trim((string)($_POST['test_value'] ?? ''));
    $hasReservation = !empty($_POST['test_has_reservation']);

    if (!in_array($testType, ['plate', 'phone'], true)) {
        $flashError = t('flash_test_type_invalid');
    } elseif ($testValue === '') {
        $flashError = t('flash_test_value_missing');
    } else {
        try {
            $service = new PlannerService($db->pdo(), $config);
            if ($testType === 'phone') {
                $res = $service->processPhone($testValue, $hasReservation);
            } else {
                $res = $service->processPlate($testValue, $hasReservation);
            }
            $testCaseResult = $res;
            $flashSuccess = t('flash_test_processed');
        } catch (Throwable $e) {
            $flashError = tr('flash_test_failed_details', ['error' => $e->getMessage()]);
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
            $flashError = t('flash_sip_manage_disabled');
        } else {
            $flashSuccess = tr('flash_sip_manage_result', ['result' => trim($out)]);
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
        $flashSuccess = t('flash_user_added');
    } catch (Throwable $e) {
        $flashError = tr('flash_user_add_failed_details', ['error' => $e->getMessage()]);
    }
}

$tab = (string)($_GET['tab'] ?? 'dashboard');
$allowedTabs = ['dashboard', 'users', 'permits', 'settings', 'logs'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'dashboard';
}
$permitsTab = (string)($_GET['permits_tab'] ?? ($_POST['permits_tab'] ?? 'new_permit'));
$allowedPermitsTabs = ['new_permit', 'permits_list', 'exceptions'];
if (!in_array($permitsTab, $allowedPermitsTabs, true)) {
    $permitsTab = 'new_permit';
}
$settingsTab = (string)($_GET['settings_tab'] ?? ($_POST['settings_tab'] ?? 'test_case'));
$allowedSettingsTabs = ['test_case', 'android_monitor', 'ampron_led', 'shelly', 'sip_agent'];
if (!in_array($settingsTab, $allowedSettingsTabs, true)) {
    $settingsTab = 'test_case';
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
// Android display monitor text defaults/settings.
$displayStandbyText = getSetting($db->pdo(), 'display_standby_text', 'Ootan andmeid...');
$displayDetectingText = getSetting($db->pdo(), 'display_detecting_text', 'Tuvastus kaib...');
$displaySuccessParkingText = getSetting($db->pdo(), 'display_success_parking_text', 'Suunata parklasse');
$displaySuccessServiceText = getSetting($db->pdo(), 'display_success_service_text', 'Suunata Service Lobby alale');
$displayFailedText = getSetting($db->pdo(), 'display_failed_text', 'Sisenemine keelatud');
$ampronEnabled = getSetting($db->pdo(), 'ampron_enabled', '0') === '1';
$ampronBaseUrl = getSetting($db->pdo(), 'ampron_base_url', '');
$ampronDisplayId = getSetting($db->pdo(), 'ampron_display_id', 'SERVICE_LOBBY');
$ampronStandbyLayout = getSetting($db->pdo(), 'ampron_standby_layout', 'service_lobby');
$ampronStandbyField = getSetting($db->pdo(), 'ampron_standby_field', 'text');
$ampronStandbyText = getSetting($db->pdo(), 'ampron_standby_text', 'Service Lobby');
$ampronStandbyExtraQuery = getSetting($db->pdo(), 'ampron_standby_extra_query', '');
$ampronPlateLayout = getSetting($db->pdo(), 'ampron_plate_layout', 'vehiclenumber');
$ampronPlateField = getSetting($db->pdo(), 'ampron_plate_field', 'plate');
$ampronPlateExtraQuery = getSetting($db->pdo(), 'ampron_plate_extra_query', '');
$ampronUsername = getSetting($db->pdo(), 'ampron_username', '');
$ampronPassword = getSetting($db->pdo(), 'ampron_password', '');
$ampronTimeout = getSetting($db->pdo(), 'ampron_timeout', '3');

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
<html lang="<?= h($lang) ?>">
<head>
  <meta charset="utf-8">
  <title><?= h(t('app_title')) ?></title>
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
    .settings-layout{display:grid;grid-template-columns:240px 1fr;gap:14px;align-items:start}
    .settings-menu{position:sticky;top:16px}
    .settings-menu .menu-link{
      display:block;
      padding:10px 12px;
      border-radius:10px;
      border:1px solid #cbd5e1;
      background:#fff;
      color:#0f172a;
      text-decoration:none;
      margin-bottom:8px;
      font-weight:600;
    }
    .settings-menu .menu-link.active{
      background:#0f766e;
      color:#fff;
      border-color:#0f766e;
    }
    @media (max-width: 900px){
      .settings-layout{grid-template-columns:1fr}
      .settings-menu{position:static}
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand">
      <img src="logo.svg" alt="Planner logo">
      <h1><?= h(t('app_title')) ?></h1>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end">
      <form method="get" style="display:flex;align-items:center;gap:8px;margin:0">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <?php if ($tab === 'permits'): ?><input type="hidden" name="permits_tab" value="<?= h($permitsTab) ?>"><?php endif; ?>
        <?php if ($tab === 'settings'): ?><input type="hidden" name="settings_tab" value="<?= h($settingsTab) ?>"><?php endif; ?>
        <?php if ($tab === 'logs'): ?><input type="hidden" name="page" value="<?= h((string)$logsPage) ?>"><?php endif; ?>
        <label for="lang" style="margin:0"><?= h(t('language')) ?></label>
        <select id="lang" name="lang" onchange="this.form.submit()" style="width:auto;min-width:120px">
          <option value="et" <?= $lang === 'et' ? 'selected' : '' ?>>Eesti</option>
          <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
          <option value="fi" <?= $lang === 'fi' ? 'selected' : '' ?>>Suomi</option>
          <option value="sv" <?= $lang === 'sv' ? 'selected' : '' ?>>Svenska</option>
          <option value="lv" <?= $lang === 'lv' ? 'selected' : '' ?>>Latviesu</option>
          <option value="lt" <?= $lang === 'lt' ? 'selected' : '' ?>>Lietuviu</option>
        </select>
      </form>
      <p style="margin:0"><strong><?= h($user['username']) ?></strong> (<?= h($user['role']) ?>) - <a href="?logout=1"><?= h(t('logout')) ?></a></p>
    </div>
  </div>

  <?php if ($flashSuccess): ?><div class="flash-ok"><?= h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError): ?><div class="flash-err"><?= h($flashError) ?></div><?php endif; ?>

  <nav class="tabs">
    <a class="tab <?= $tab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard"><?= h(t('menu_dashboard')) ?></a>
    <a class="tab <?= $tab === 'users' ? 'active' : '' ?>" href="?tab=users"><?= h(t('menu_users')) ?></a>
    <a class="tab <?= $tab === 'permits' ? 'active' : '' ?>" href="?tab=permits"><?= h(t('menu_permits')) ?></a>
    <a class="tab <?= $tab === 'settings' ? 'active' : '' ?>" href="?tab=settings"><?= h(t('menu_settings')) ?></a>
    <a class="tab <?= $tab === 'logs' ? 'active' : '' ?>" href="?tab=logs"><?= h(t('menu_logs')) ?></a>
  </nav>

  <?php if ($tab === 'dashboard'): ?>
  <section class="kpi">
    <article class="card"><h2><?= h(t('dashboard_permits')) ?></h2><div class="value"><?= h((string)$stats['rules']) ?></div></article>
    <article class="card"><h2><?= h(t('dashboard_log_entries')) ?></h2><div class="value"><?= h((string)$stats['entries']) ?></div></article>
  </section>
  <div class="grid" style="margin-top:14px">
    <section class="card">
      <h2><?= h(t('dashboard_recent_permits')) ?></h2>
      <ul>
      <?php foreach (array_slice($rules, 0, 10) as $r): ?>
        <li><span class="pill"><?= h($r['subject_type']) ?></span> <?= h($r['subject_value']) ?> | <?= h(formatScheduleLabel((string)$r['schedule'])) ?></li>
      <?php endforeach; ?>
      </ul>
    </section>
    <section class="card">
      <h2><?= h(t('dashboard_recent_entries')) ?></h2>
      <ul>
      <?php foreach ($entries as $e): ?>
      <li><?= h($e['created_at']) ?> - <?= h($e['input_type']) ?>:<?= h($e['input_value']) ?> => <?= h(decisionLabel((int)$e['allowed'] === 1)) ?> (<?= h(reasonLabel((string)($e['reason'] ?? ''))) ?>)</li>
      <?php endforeach; ?>
      </ul>
    </section>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'permits'): ?>
  <div class="settings-layout">
    <aside class="card settings-menu">
      <a class="menu-link <?= $permitsTab === 'new_permit' ? 'active' : '' ?>" href="?tab=permits&permits_tab=new_permit"><?= h(t('permits_new')) ?></a>
      <a class="menu-link <?= $permitsTab === 'permits_list' ? 'active' : '' ?>" href="?tab=permits&permits_tab=permits_list"><?= h(t('permits_list')) ?></a>
      <a class="menu-link <?= $permitsTab === 'exceptions' ? 'active' : '' ?>" href="?tab=permits&permits_tab=exceptions"><?= h(t('permits_exceptions')) ?></a>
    </aside>

    <div>
      <?php if ($permitsTab === 'new_permit'): ?>
      <section class="card">
        <h2><?= h(t('permits_new_title')) ?></h2>
        <form method="post" id="ruleForm">
          <input type="hidden" name="permits_tab" value="new_permit">
          <label><?= h(t('label_type')) ?></label>
          <select name="subject_type">
            <option value="plate"><?= h(t('input_plate')) ?></option>
            <option value="phone"><?= h(t('input_phone')) ?></option>
          </select>
          <label><?= h(t('label_value')) ?></label>
          <input name="subject_value" placeholder="<?= h(t('placeholder_plate_or_phone')) ?>" required>
          <label><?= h(t('label_zone')) ?></label>
          <select name="zone">
            <option value="parking"><?= h(t('zone_parking')) ?></option>
            <option value="service_lobby"><?= h(t('zone_service_lobby')) ?></option>
          </select>
          <label><input type="checkbox" id="schedule247" name="schedule_247" value="1" checked style="width:auto"> <?= h(t('schedule_247')) ?></label>
          <div id="customSchedule" style="display:none">
            <label><?= h(t('label_days')) ?></label>
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
              <div><label><?= h(t('label_start')) ?></label><input type="time" name="start_time" value="07:00"></div>
              <div><label><?= h(t('label_end')) ?></label><input type="time" name="end_time" value="19:00"></div>
            </div>
            <p class="hint"><?= h(t('hint_overnight_supported')) ?></p>
          </div>
          <button name="add_rule" value="1"><?= h(t('btn_save_permit')) ?></button>
        </form>
      </section>
      <?php endif; ?>

      <?php if ($permitsTab === 'permits_list'): ?>
      <section class="card">
        <h2><?= h(t('permits_list')) ?></h2>
        <ul>
        <?php foreach ($rules as $r): ?>
          <li><span class="pill"><?= h($r['subject_type']) ?></span> <?= h($r['subject_value']) ?> | <?= h(formatScheduleLabel((string)$r['schedule'])) ?> | <?= h($r['zone']) ?> | <?= h($r['source']) ?></li>
        <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <?php if ($permitsTab === 'exceptions'): ?>
      <section class="card">
        <h2><?= h(t('permits_exceptions')) ?></h2>
        <form method="post">
          <input type="hidden" name="permits_tab" value="exceptions">
          <label><?= h(t('label_exception_name')) ?></label>
          <input name="exception_name" placeholder="<?= h(t('placeholder_exception_name')) ?>" required>
          <label><?= h(t('label_type')) ?></label>
          <select name="exception_input_type">
            <option value="plate"><?= h(t('input_plate')) ?></option>
            <option value="phone"><?= h(t('input_phone')) ?></option>
          </select>
          <label><?= h(t('label_schedule')) ?></label>
          <input name="exception_schedule" placeholder="<?= h(t('placeholder_exception_schedule')) ?>" required>
          <label><?= h(t('label_zone')) ?></label>
          <select name="exception_zone">
            <option value="parking"><?= h(t('zone_parking')) ?></option>
            <option value="service_lobby"><?= h(t('zone_service_lobby')) ?></option>
          </select>
          <label><input type="checkbox" name="exception_enabled" value="1" style="width:auto" checked> <?= h(t('label_active')) ?></label>
          <button name="add_exception" value="1"><?= h(t('btn_add_exception')) ?></button>
        </form>
        <hr style="margin:14px 0;border:0;border-top:1px solid #dbe2ea">
        <?php foreach ($exceptions as $ex): ?>
        <form method="post" style="padding:10px;border:1px solid #dbe2ea;border-radius:10px;margin-bottom:10px">
          <input type="hidden" name="permits_tab" value="exceptions">
          <input type="hidden" name="exception_id" value="<?= h((string)$ex['id']) ?>">
          <label><?= h(t('label_name')) ?></label>
          <input name="exception_name" value="<?= h((string)$ex['name']) ?>" required>
          <label><?= h(t('label_type')) ?></label>
          <select name="exception_input_type">
            <option value="plate" <?= $ex['input_type'] === 'plate' ? 'selected' : '' ?>><?= h(t('input_plate')) ?></option>
            <option value="phone" <?= $ex['input_type'] === 'phone' ? 'selected' : '' ?>><?= h(t('input_phone')) ?></option>
          </select>
          <label><?= h(t('label_schedule')) ?></label>
          <input name="exception_schedule" value="<?= h((string)$ex['schedule']) ?>" required>
          <label><?= h(t('label_zone')) ?></label>
          <select name="exception_zone">
            <option value="parking" <?= $ex['zone'] === 'parking' ? 'selected' : '' ?>><?= h(t('zone_parking')) ?></option>
            <option value="service_lobby" <?= $ex['zone'] === 'service_lobby' ? 'selected' : '' ?>><?= h(t('zone_service_lobby')) ?></option>
          </select>
          <label><input type="checkbox" name="exception_enabled" value="1" style="width:auto" <?= ((int)$ex['enabled'] === 1) ? 'checked' : '' ?>> <?= h(t('label_active')) ?></label>
          <p class="hint"><?= h(t('label_target_no_permit')) ?> (<?= h((string)$ex['target']) ?>) | <?= h(t('label_created')) ?>: <?= h((string)$ex['created_at']) ?></p>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button style="width:auto" name="save_exception" value="1"><?= h(t('btn_save_changes')) ?></button>
            <button style="width:auto;background:#b91c1c" name="delete_exception" value="1" onclick="return confirm('<?= h(t('confirm_delete_exception')) ?>')"><?= h(t('btn_delete')) ?></button>
          </div>
        </form>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'settings'): ?>
  <div class="settings-layout">
    <aside class="card settings-menu">
      <a class="menu-link <?= $settingsTab === 'test_case' ? 'active' : '' ?>" href="?tab=settings&settings_tab=test_case"><?= h(t('settings_test_case')) ?></a>
      <a class="menu-link <?= $settingsTab === 'android_monitor' ? 'active' : '' ?>" href="?tab=settings&settings_tab=android_monitor"><?= h(t('settings_android_monitor')) ?></a>
      <a class="menu-link <?= $settingsTab === 'ampron_led' ? 'active' : '' ?>" href="?tab=settings&settings_tab=ampron_led"><?= h(t('settings_ampron_led')) ?></a>
      <a class="menu-link <?= $settingsTab === 'shelly' ? 'active' : '' ?>" href="?tab=settings&settings_tab=shelly"><?= h(t('settings_shelly_relay')) ?></a>
      <a class="menu-link <?= $settingsTab === 'sip_agent' ? 'active' : '' ?>" href="?tab=settings&settings_tab=sip_agent"><?= h(t('sip_title')) ?></a>
    </aside>

    <div>
      <?php if ($settingsTab === 'test_case'): ?>
      <section class="card">
        <h2><?= h(t('settings_test_case')) ?></h2>
        <form method="post">
          <input type="hidden" name="settings_tab" value="test_case">
          <label><?= h(t('label_type')) ?></label>
          <select name="test_type">
            <option value="plate"><?= h(t('input_plate')) ?></option>
            <option value="phone"><?= h(t('input_phone')) ?></option>
          </select>
          <label><?= h(t('label_value')) ?></label>
          <input name="test_value" placeholder="<?= h(t('placeholder_test_value')) ?>" required>
          <label><input type="checkbox" name="test_has_reservation" value="1" style="width:auto"> <?= h(t('label_has_reservation')) ?> = true</label>
          <button name="test_case" value="1"><?= h(t('btn_run_test_case')) ?></button>
        </form>
        <?php if (is_array($testCaseResult)): ?>
        <p class="hint"><?= h(t('label_response')) ?>:</p>
        <pre style="white-space:pre-wrap;background:#0b1020;color:#dbeafe;padding:10px;border-radius:10px;overflow:auto;"><?= h(json_encode($testCaseResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($settingsTab === 'android_monitor'): ?>
      <section class="card">
        <h2><?= h(t('settings_android_monitor')) ?></h2>
        <form method="post">
          <input type="hidden" name="settings_tab" value="android_monitor">
          <label><?= h(t('label_standby_text')) ?></label>
          <input name="display_standby_text" value="<?= h($displayStandbyText) ?>" required>
          <label><?= h(t('label_detecting_text')) ?></label>
          <input name="display_detecting_text" value="<?= h($displayDetectingText) ?>" required>
          <label><?= h(t('label_success_parking')) ?></label>
          <input name="display_success_parking_text" value="<?= h($displaySuccessParkingText) ?>" required>
          <label><?= h(t('label_success_service_lobby')) ?></label>
          <input name="display_success_service_text" value="<?= h($displaySuccessServiceText) ?>" required>
          <label><?= h(t('label_failed_detection')) ?></label>
          <input name="display_failed_text" value="<?= h($displayFailedText) ?>" required>
          <button name="save_display_monitor" value="1"><?= h(t('btn_save_android_monitor')) ?></button>
        </form>
        <p class="hint"><?= h(t('hint_android_display_config')) ?> <code>action=display-config</code>.</p>
      </section>
      <?php endif; ?>

      <?php if ($settingsTab === 'ampron_led'): ?>
      <section class="card">
        <h2><?= h(t('ampron_title')) ?></h2>
        <form method="post">
          <input type="hidden" name="settings_tab" value="ampron_led">
          <label><input type="checkbox" name="ampron_enabled" value="1" style="width:auto" <?= $ampronEnabled ? 'checked' : '' ?>> <?= h(t('label_ampron_enabled')) ?></label>
          <label><?= h(t('label_ampron_base_url')) ?></label>
          <input name="ampron_base_url" value="<?= h($ampronBaseUrl) ?>" placeholder="http://DISPLAY_IP:9527 voi http://DISPLAY_IP:9527/mlds">
          <label><?= h(t('label_display_id')) ?></label>
          <input name="ampron_display_id" value="<?= h($ampronDisplayId) ?>" placeholder="SERVICE_LOBBY" required>
          <label><?= h(t('label_standby_layout')) ?></label>
          <input name="ampron_standby_layout" value="<?= h($ampronStandbyLayout) ?>" placeholder="service_lobby" required>
          <label><?= h(t('label_standby_field')) ?></label>
          <input name="ampron_standby_field" value="<?= h($ampronStandbyField) ?>" placeholder="text" required>
          <label><?= h(t('label_standby_text')) ?></label>
          <input name="ampron_standby_text" value="<?= h($ampronStandbyText) ?>" placeholder="Service Lobby" required>
          <label><?= h(t('label_standby_extra_query')) ?></label>
          <input name="ampron_standby_extra_query" value="<?= h($ampronStandbyExtraQuery) ?>" placeholder="kiosk=21">
          <label><?= h(t('label_plate_layout')) ?></label>
          <input name="ampron_plate_layout" value="<?= h($ampronPlateLayout) ?>" placeholder="vehiclenumber" required>
          <label><?= h(t('label_plate_field')) ?></label>
          <input name="ampron_plate_field" value="<?= h($ampronPlateField) ?>" placeholder="plate" required>
          <label><?= h(t('label_plate_extra_query')) ?></label>
          <input name="ampron_plate_extra_query" value="<?= h($ampronPlateExtraQuery) ?>" placeholder="kiosk=21">
          <label><?= h(t('label_http_user_optional')) ?></label>
          <input name="ampron_username" value="<?= h($ampronUsername) ?>" placeholder="admin">
          <label><?= h(t('label_http_password_optional')) ?></label>
          <input name="ampron_password" type="password" value="" placeholder="<?= $ampronPassword !== '' ? h(t('placeholder_keep_old_password')) : '' ?>">
          <label><?= h(t('label_http_timeout_seconds')) ?></label>
          <input name="ampron_timeout" value="<?= h($ampronTimeout) ?>" placeholder="3" required>
          <button name="save_ampron_display" value="1"><?= h(t('btn_save_ampron_settings')) ?></button>
        </form>
        <p class="hint"><?= h(t('hint_ampron_request')) ?> <code>/mlds?id=...&amp;layout=...&amp;FIELD=...</code>.</p>
        <p class="hint"><?= h(t('hint_ampron_behavior')) ?></p>
      </section>
      <?php endif; ?>

      <?php if ($settingsTab === 'shelly'): ?>
      <section class="card">
        <h2><?= h(t('shelly_title')) ?></h2>
        <form method="post">
          <input type="hidden" name="settings_tab" value="shelly">
          <label><?= h(t('label_shelly_base_url')) ?></label>
          <input name="shelly_base_url" value="<?= h($shellyBaseUrl) ?>" placeholder="http://shelly-ip" required>
          <label><?= h(t('label_shelly_mode')) ?></label>
          <select name="shelly_mode">
            <option value="auto" <?= $shellyMode === 'auto' ? 'selected' : '' ?>><?= h(t('shelly_mode_auto')) ?></option>
            <option value="rpc" <?= $shellyMode === 'rpc' ? 'selected' : '' ?>><?= h(t('shelly_mode_rpc')) ?></option>
            <option value="relay" <?= $shellyMode === 'relay' ? 'selected' : '' ?>><?= h(t('shelly_mode_relay')) ?></option>
          </select>
          <label><?= h(t('label_switch_id')) ?></label>
          <input name="shelly_switch_id" value="<?= h($shellySwitchId) ?>" placeholder="0" required>
          <label><?= h(t('label_toggle_after_seconds')) ?></label>
          <input name="shelly_toggle_after" value="<?= h($shellyToggleAfter) ?>" placeholder="1" required>
          <label><?= h(t('label_username_optional')) ?></label>
          <input name="shelly_username" value="<?= h($shellyUsername) ?>" placeholder="admin">
          <label><?= h(t('label_password_optional')) ?></label>
          <input name="shelly_password" type="password" value="" placeholder="<?= h(t('placeholder_keep_old_password')) ?>">
          <p class="hint"><?= h(t('hint_shelly_pro')) ?></p>
          <p class="hint"><?= h(t('hint_password_keep_old')) ?></p>
          <button name="save_shelly" value="1"><?= h(t('btn_save_shelly_settings')) ?></button>
        </form>
      </section>
      <?php endif; ?>

      <?php if ($settingsTab === 'sip_agent'): ?>
      <section class="card">
        <h2><?= h(t('sip_title')) ?></h2>
        <p class="hint"><?= h(t('label_status')) ?>: <strong><?= h($sipAgentStatus) ?></strong></p>
        <form method="post">
          <input type="hidden" name="settings_tab" value="sip_agent">
          <label><input type="checkbox" name="sip_agent_enabled" value="1" style="width:auto" <?= $sipAgentEnabled ? 'checked' : '' ?>> <?= h(t('label_sip_enabled')) ?></label>
          <label><?= h(t('label_planner_api_url_phone_event')) ?></label>
          <input name="planner_api_url" value="<?= h($plannerApiUrl) ?>" placeholder="https://one.crebit.eu/pln/api.php" required>
          <label><?= h(t('label_sip_user')) ?></label>
          <input name="sip_user" value="<?= h($sipUser) ?>" placeholder="1001" required>
          <label><?= h(t('label_sip_password')) ?></label>
          <input name="sip_password" type="password" value="" placeholder="<?= h(t('placeholder_keep_old_password')) ?>">
          <label><?= h(t('label_sip_domain')) ?></label>
          <input name="sip_domain" value="<?= h($sipDomain) ?>" placeholder="sip.example.com" required>
          <label><?= h(t('label_sip_transport')) ?></label>
          <select name="sip_transport">
            <option value="udp" <?= $sipTransport === 'udp' ? 'selected' : '' ?>>udp</option>
            <option value="tcp" <?= $sipTransport === 'tcp' ? 'selected' : '' ?>>tcp</option>
            <option value="tls" <?= $sipTransport === 'tls' ? 'selected' : '' ?>>tls</option>
          </select>
          <label><?= h(t('label_display_name_optional')) ?></label>
          <input name="sip_display_name" value="<?= h($sipDisplayName) ?>" placeholder="Planner Gate">
          <label><?= h(t('label_outbound_proxy_optional')) ?></label>
          <input name="sip_outbound" value="<?= h($sipOutbound) ?>" placeholder="proxy.example.com">
          <label><?= h(t('label_registration_interval_seconds')) ?></label>
          <input name="sip_regint" value="<?= h($sipRegint) ?>" placeholder="300">
          <button name="save_sip_agent" value="1"><?= h(t('btn_save_sip_settings')) ?></button>
        </form>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="settings_tab" value="sip_agent">
          <button name="sip_agent_action" value="start"><?= h(t('btn_start_sip_agent')) ?></button>
          <button name="sip_agent_action" value="restart"><?= h(t('btn_restart_sip_agent')) ?></button>
          <button name="sip_agent_action" value="stop"><?= h(t('btn_stop_sip_agent')) ?></button>
        </form>
      </section>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'users'): ?>
  <div class="grid">
    <?php if ($user['role'] === 'admin'): ?>
    <section class="card">
      <h2><?= h(t('users_add')) ?></h2>
      <form method="post">
        <label><?= h(t('username')) ?></label><input name="username" required>
        <label><?= h(t('password')) ?></label><input name="password" type="password" required>
        <label><?= h(t('label_role')) ?></label>
        <select name="role">
          <option value="viewer">viewer</option>
          <option value="operator">operator</option>
          <option value="admin">admin</option>
        </select>
        <button name="add_user" value="1"><?= h(t('btn_add_user')) ?></button>
      </form>
    </section>
    <?php endif; ?>
    <section class="card">
      <h2><?= h(t('users_list')) ?></h2>
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
    <h2><?= h(t('logs_title')) ?></h2>
    <p class="muted"><?= h(tr('logs_pagination_summary', ['page' => (string)$logsPage, 'total_pages' => (string)$logsTotalPages, 'total' => (string)$logsTotal])) ?></p>
    <ul>
    <?php foreach ($logEntries as $e): ?>
      <li><?= h($e['created_at']) ?> - <?= h($e['input_type']) ?>:<?= h($e['input_value']) ?> => <?= h(decisionLabel((int)$e['allowed'] === 1)) ?> (<?= h(reasonLabel((string)($e['reason'] ?? ''))) ?>)</li>
    <?php endforeach; ?>
    </ul>
    <div style="display:flex;gap:8px;margin-top:12px">
      <?php if ($logsPage > 1): ?>
        <a class="tab" href="?tab=logs&page=<?= h((string)($logsPage - 1)) ?>"><?= h(t('btn_previous')) ?></a>
      <?php endif; ?>
      <?php if ($logsPage < $logsTotalPages): ?>
        <a class="tab" href="?tab=logs&page=<?= h((string)($logsPage + 1)) ?>"><?= h(t('btn_next')) ?></a>
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
