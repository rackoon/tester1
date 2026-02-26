<!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8">
  <title>Planner Gate Control</title>
</head>
<body>
  <h1>Planner Gate Control</h1>
  <p>Liiklusplanner on töös. Ava <a href="admin.php">admin liides</a>.</p>
  <h2>API</h2>
  <ul>
    <li>POST <code>api.php?action=plate-event</code> - Frigate ANPR sündmus</li>
    <li>POST <code>api.php?action=phone-event</code> - VoIP kõne sündmus</li>
    <li>GET <code>api.php?action=display-feed</code> - Android ekraani feed</li>
  </ul>
<?php include 'include/footer.inc.php'; ?>
</body>
</html>
