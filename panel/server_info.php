<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_login();
$pdo = db();
$root = dirname(__DIR__);

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM server_info WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        flash('Eintrag gelöscht.');
    } elseif ($action === 'crawl') {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            flash('Der Start aus dem Panel ist auf diesem Server nicht möglich. Bitte "php crawl.php" in der Konsole ausführen.');
        } else {
            $php = escapeshellarg(PHP_BINARY);
            $script = escapeshellarg($root . '/crawl.php');
            $log = escapeshellarg($root . '/crawl.log');
            if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
                pclose(popen('start /B "" ' . $php . ' ' . $script . ' > ' . $log . ' 2>&1', 'r'));
            } else {
                exec('nohup ' . $php . ' ' . $script . ' > ' . $log . ' 2>&1 &');
            }
            flash('Durchsuchung gestartet. Fortschritt unten in "Letzte Ausgabe" bzw. in crawl.log.');
        }
    }
    header('Location: server_info.php');
    exit;
}

// ------------------------------------------------------------ Anzeige
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 25;
$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE content LIKE ? OR channel_name LIKE ?';
    $params = ['%' . $q . '%', '%' . $q . '%'];
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM server_info $where");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();

$st = $pdo->prepare("SELECT * FROM server_info $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$rows = $st->fetchAll();

$channels = $pdo->query('SELECT * FROM crawl_channels ORDER BY status, channel_name')->fetchAll();

page_header('Server-Infos', 'server_info.php');
echo '<h1>Server-Infos</h1>';

echo '<div class="card"><h2>Server durchsuchen</h2>'
    . '<p class="mute">Liest die Verläufe der Channels und speichert Frage/Antwort-Paare und Team-Infos. '
    . 'Channels, die nur für das Team sichtbar sind, werden nicht gelesen.</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="crawl"><button>Durchsuchung starten</button></form></div>';

$logFile = $root . '/crawl.log';
if (is_file($logFile)) {
    $tail = implode("\n", array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -15));
    echo '<div class="card"><h2>Letzte Ausgabe</h2><pre style="white-space:pre-wrap;margin:0" class="mute">' . h($tail) . '</pre></div>';
}

echo '<div class="card"><h2>Channels (' . count($channels) . ')</h2><table><tr><th>Channel</th><th>Status</th><th>Nachrichten</th><th>Hinweis</th></tr>';
foreach ($channels as $c) {
    $label = ['read' => 'gelesen', 'skipped' => 'übersprungen', 'error' => 'Fehler'][$c['status']] ?? $c['status'];
    echo '<tr><td>#' . h($c['channel_name']) . '</td><td>' . h($label) . '</td><td>' . (int) $c['messages_read']
        . '</td><td class="mute">' . h($c['reason']) . '</td></tr>';
}
if (!$channels) {
    echo '<tr><td colspan="4" class="mute">Noch keine Durchsuchung.</td></tr>';
}
echo '</table></div>';

echo '<div class="card"><form method="get" class="row"><div><label>Suche in Infos (' . $total . ')</label><input name="q" value="' . h($q)
    . '"></div><button>Suchen</button></form></div>';

echo '<div class="card"><table><tr><th>Channel</th><th>Text</th><th>Datum</th><th></th></tr>';
foreach ($rows as $r) {
    echo '<tr><td>#' . h($r['channel_name']) . '</td><td>' . h(mb_strimwidth($r['content'], 0, 220, '…')) . '</td><td class="mute">'
        . h($r['created_at']) . '</td><td><form method="post" class="inline" onsubmit="return confirm(\'Löschen?\')">' . csrf_field()
        . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $r['id']
        . '"><button class="del">Löschen</button></form></td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="4" class="mute">Keine Infos gespeichert.</td></tr>';
}
echo '</table><div class="pager">';
$qs = $q !== '' ? '&q=' . urlencode($q) : '';
if ($page > 1) {
    echo '<a class="btn sec" href="?p=' . ($page - 1) . $qs . '">« Zurück</a>';
}
if ($page * $per < $total) {
    echo '<a class="btn sec" href="?p=' . ($page + 1) . $qs . '">Weiter »</a>';
}
echo '</div></div>';
page_footer();
