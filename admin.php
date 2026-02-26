<?php
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';

$config = require __DIR__ . '/config.php';
$db = new Database($config);
$auth = new Auth($db->pdo());

if (isset($_POST['login'])) {
    $ok = $auth->login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if (!$ok) {
        $error = 'Vale kasutajanimi või parool';
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
<body>
<h1>Planner admin login</h1>
<?php if (!empty($error)): ?><p style="color:red"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
  <input name="username" placeholder="Kasutajanimi" required>
  <input name="password" type="password" placeholder="Parool" required>
  <button name="login" value="1">Logi sisse</button>
</form>
<p>Vaikimisi: admin / admin123</p>
</body>
</html>
<?php
exit;
endif;

if (isset($_POST['add_rule'])) {
    $auth->requireRole(['admin', 'operator']);
    $stmt = $db->pdo()->prepare('INSERT INTO access_rules(subject_type,subject_value,schedule,zone,source,created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $_POST['subject_type'],
        strtoupper(trim($_POST['subject_value'])),
        strtoupper(trim($_POST['schedule'])),
        $_POST['zone'],
        'local',
        date(DATE_ATOM),
    ]);
}

if (isset($_POST['add_sip'])) {
    $auth->requireRole(['admin']);
    $stmt = $db->pdo()->prepare('INSERT INTO sip_clients(name,sip_server,sip_user,sip_password,ext_number,created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $_POST['name'],
        $_POST['sip_server'],
        $_POST['sip_user'],
        $_POST['sip_password'],
        $_POST['ext_number'],
        date(DATE_ATOM),
    ]);
}

if (isset($_POST['add_user'])) {
    $auth->requireRole(['admin']);
    $stmt = $db->pdo()->prepare('INSERT INTO users(username,password_hash,role,created_at) VALUES (?,?,?,?)');
    $stmt->execute([
        $_POST['username'],
        password_hash($_POST['password'], PASSWORD_DEFAULT),
        $_POST['role'],
        date(DATE_ATOM),
    ]);
}

$rules = $db->pdo()->query('SELECT * FROM access_rules ORDER BY id DESC LIMIT 50')->fetchAll();
$entries = $db->pdo()->query('SELECT * FROM entries ORDER BY id DESC LIMIT 20')->fetchAll();
$sip = $db->pdo()->query('SELECT id,name,sip_server,sip_user,ext_number,created_at FROM sip_clients ORDER BY id DESC')->fetchAll();
?>
<!doctype html>
<html lang="et">
<body>
<h1>Planner admin</h1>
<p>Sisse logitud: <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['role']) ?>) - <a href="?logout=1">Logi välja</a></p>

<h2>Ligipääsu reegel</h2>
<form method="post">
  <select name="subject_type"><option value="plate">Auto nr</option><option value="phone">Telefon</option></select>
  <input name="subject_value" placeholder="ABC123 või +372..." required>
  <input name="schedule" placeholder="E-R 7-19 või 24/7" required>
  <select name="zone"><option value="parking">parking</option><option value="service_lobby">service_lobby</option></select>
  <button name="add_rule" value="1">Salvesta</button>
</form>

<h2>SIP kliendid (VoIP värava avamine)</h2>
<form method="post">
  <input name="name" placeholder="Nimi" required>
  <input name="sip_server" placeholder="sip.example.com" required>
  <input name="sip_user" placeholder="Kasutaja" required>
  <input name="sip_password" placeholder="Parool" required>
  <input name="ext_number" placeholder="Sisene number" required>
  <button name="add_sip" value="1">Lisa SIP klient</button>
</form>
<ul>
<?php foreach ($sip as $c): ?>
  <li><?= htmlspecialchars($c['name']) ?> / <?= htmlspecialchars($c['sip_server']) ?> / <?= htmlspecialchars($c['ext_number']) ?></li>
<?php endforeach; ?>
</ul>

<?php if ($user['role'] === 'admin'): ?>
<h2>Kasutajad ja õigused</h2>
<form method="post">
  <input name="username" placeholder="Kasutajanimi" required>
  <input name="password" type="password" placeholder="Parool" required>
  <select name="role"><option value="viewer">viewer</option><option value="operator">operator</option><option value="admin">admin</option></select>
  <button name="add_user" value="1">Lisa kasutaja</button>
</form>
<?php endif; ?>

<h2>Reeglid</h2>
<ul>
<?php foreach ($rules as $r): ?>
  <li><?= htmlspecialchars($r['subject_type']) ?>: <?= htmlspecialchars($r['subject_value']) ?> | <?= htmlspecialchars($r['schedule']) ?> | <?= htmlspecialchars($r['zone']) ?> | <?= htmlspecialchars($r['source']) ?></li>
<?php endforeach; ?>
</ul>

<h2>Viimased sisenemised</h2>
<ul>
<?php foreach ($entries as $e): ?>
  <li><?= htmlspecialchars($e['created_at']) ?> - <?= htmlspecialchars($e['input_type']) ?>:<?= htmlspecialchars($e['input_value']) ?> => <?= $e['allowed'] ? 'ALLOWED' : 'DENIED' ?> (<?= htmlspecialchars($e['reason']) ?>)</li>
<?php endforeach; ?>
</ul>
</body>
</html>
