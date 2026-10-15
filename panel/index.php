<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Env;

// ------------------------------------------------------------ Login
$error = null;
if (!logged_in()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $now = time();
        $_SESSION['fails'] = array_values(array_filter($_SESSION['fails'] ?? [], fn($t) => $now - $t < 300));
        if (count($_SESSION['fails']) >= 5) {
            $error = 'Zu viele Versuche. Bitte 5 Minuten warten.';
        } else {
            $user = (string) ($_POST['user'] ?? '');
            $pass = (string) ($_POST['pass'] ?? '');
            $hash = Env::get('PANEL_PASSWORD_HASH');
            if ($hash !== '' && hash_equals(Env::get('PANEL_USER', 'admin'), $user) && password_verify($pass, $hash)) {
                session_regenerate_id(true);
                $_SESSION['user'] = $user;
                $_SESSION['fails'] = [];
                header('Location: index.php');
                exit;
            }
            $_SESSION['fails'][] = $now;
            $error = 'Benutzername oder Passwort falsch.';
        }
    }
    page_header('Login');
    echo '<div class="card" style="max-width:360px;margin:60px auto"><h1>Anmelden</h1>';
    if ($error) {
        echo '<div class="flash err">' . h($error) . '</div>';
    }
    echo '<form method="post">' . csrf_field()
        . '<label>Benutzername</label><input name="user" autofocus required>'
        . '<label>Passwort</label><input name="pass" type="password" required>'
        . '<p><button>Anmelden</button></p></form></div>';
    page_footer();
    exit;
}

// ------------------------------------------------------------ Dashboard
$pdo = db();
$count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

$stats = [
    'Gelernte Antworten' => $count('SELECT COUNT(*) FROM knowledge'),
    'Antworten gegeben' => $count('SELECT COALESCE(SUM(uses),0) FROM knowledge'),
    'Eingriffe (24 h)' => $count("SELECT COUNT(*) FROM interventions WHERE created_at >= NOW() - INTERVAL 1 DAY"),
    'Eingriffe (7 Tage)' => $count("SELECT COUNT(*) FROM interventions WHERE created_at >= NOW() - INTERVAL 7 DAY"),
];
$top = $pdo->query('SELECT id, question, uses FROM knowledge WHERE uses > 0 ORDER BY uses DESC LIMIT 5')->fetchAll();
$recent = $pdo->query('SELECT question, answer, created_at FROM knowledge ORDER BY id DESC LIMIT 5')->fetchAll();
$events = $pdo->query('SELECT channel_id, user_ids, heat, created_at FROM interventions ORDER BY id DESC LIMIT 5')->fetchAll();

page_header('Dashboard', 'index.php');
echo '<h1>Dashboard</h1><div class="grid">';
foreach ($stats as $label => $value) {
    echo '<div class="card"><div class="stat">' . $value . '</div><div class="mute">' . h($label) . '</div></div>';
}
echo '</div>';

echo '<div class="card"><h2>Zuletzt gelernt</h2><table><tr><th>Frage</th><th>Antwort</th><th>Zeit</th></tr>';
foreach ($recent as $r) {
    echo '<tr><td>' . h(mb_strimwidth($r['question'], 0, 90, '…')) . '</td><td>' . h(mb_strimwidth($r['answer'], 0, 90, '…'))
        . '</td><td class="mute">' . h($r['created_at']) . '</td></tr>';
}
if (!$recent) {
    echo '<tr><td colspan="3" class="mute">Noch nichts gelernt.</td></tr>';
}
echo '</table></div>';

echo '<div class="card"><h2>Meistgenutzte Antworten</h2><table><tr><th>Frage</th><th>Nutzungen</th></tr>';
foreach ($top as $r) {
    echo '<tr><td><a href="knowledge.php?edit=' . (int) $r['id'] . '">' . h(mb_strimwidth($r['question'], 0, 100, '…'))
        . '</a></td><td>' . (int) $r['uses'] . '</td></tr>';
}
if (!$top) {
    echo '<tr><td colspan="2" class="mute">Noch keine Nutzung.</td></tr>';
}
echo '</table></div>';

echo '<div class="card"><h2>Letzte Eingriffe</h2><table><tr><th>Channel</th><th>Beteiligte</th><th>Hitze</th><th>Zeit</th></tr>';
foreach ($events as $e) {
    echo '<tr><td>' . h($e['channel_id']) . '</td><td>' . h($e['user_ids']) . '</td><td>' . (int) $e['heat']
        . '</td><td class="mute">' . h($e['created_at']) . '</td></tr>';
}
if (!$events) {
    echo '<tr><td colspan="4" class="mute">Keine Eingriffe.</td></tr>';
}
echo '</table></div>';
page_footer();
