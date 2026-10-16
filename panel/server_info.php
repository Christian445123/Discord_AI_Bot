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
    } elseif ($action === 'purge') {
        // Doppelte Absicherung: Bestaetigungswort muss exakt eingegeben werden
        if (trim((string) ($_POST['confirm'] ?? '')) !== 'LÖSCHEN') {
            flash('Nicht gelöscht: Das Bestätigungswort LÖSCHEN wurde nicht korrekt eingegeben.');
        } else {
            $done = [];
            $busy = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE type IN ('crawl','crawl_full') AND status IN ('pending','running')")->fetchColumn();
            if ($busy > 0) {
                flash('Nicht gelöscht: Es läuft gerade eine Durchsuchung. Bitte warten, bis sie beendet ist.');
                header('Location: server_info.php');
                exit;
            }
            if (!empty($_POST['purge_info'])) {
                $n = $pdo->exec('DELETE FROM server_info');
                $done[] = "$n Server-Infos";
            }
            if (!empty($_POST['purge_knowledge'])) {
                $n = $pdo->exec("DELETE FROM knowledge WHERE source = 'crawl'");
                $done[] = "$n gelernte Antworten aus der Durchsuchung";
            }
            if (!empty($_POST['purge_state'])) {
                $n = $pdo->exec('DELETE FROM crawl_channels');
                $done[] = "Durchsuchungs-Stand ($n Channels) zurückgesetzt";
            }
            flash($done ? 'Gelöscht: ' . implode(', ', $done) . '.' : 'Nichts ausgewählt - es wurde nichts gelöscht.');
        }
    } elseif ($action === 'import_web') {
        flash(dispatch_job($pdo, 'import_web', 'Website einlesen'));
    } elseif ($action === 'crawl' || $action === 'crawl_full') {
        // Bot online: er fuehrt den Auftrag aus. Sonst startet das Panel die Durchsuchung selbst.
        flash(dispatch_job($pdo, $action, $action === 'crawl' ? 'Durchsuchung' : 'Komplett neu einlesen'));
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
    . '<div class="row" style="gap:10px"><form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="crawl"><button>Durchsuchung starten</button></form>'
    . '<form method="post" class="inline" onsubmit="return confirm(\'Alle Channels komplett von vorn einlesen? Das kann länger dauern.\')">' . csrf_field()
    . '<input type="hidden" name="action" value="crawl_full"><button class="sec">Komplett neu einlesen</button></form>'
    . '<form method="post" class="inline">' . csrf_field()
    . '<input type="hidden" name="action" value="import_web"><button class="sec">Website einlesen</button></form></div>'
    . '<p class="mute" style="margin-top:8px">„Durchsuchung starten“ liest nur neue Nachrichten. „Komplett neu einlesen“ liest alle Channels wieder von Anfang an.</p>';

$hb = $pdo->query("SELECT updated_at FROM bot_status WHERE k = 'heartbeat'")->fetchColumn();
$online = $hb && (time() - strtotime((string) $hb)) < 30;
echo '<p class="mute" style="margin-top:12px">Bot: ' . ($online ? '<b style="color:var(--ok)">online</b>' : '<b style="color:var(--bad)">offline</b> - Aufträge führt dann das Panel selbst aus') . '</p>';

$webJob = $pdo->query("SELECT * FROM jobs WHERE type = 'import_web' ORDER BY id DESC LIMIT 1")->fetch();
if ($webJob) {
    $wl = ['pending' => 'wartet', 'running' => 'läuft', 'done' => 'abgeschlossen', 'failed' => 'fehlgeschlagen'][$webJob['status']] ?? $webJob['status'];
    echo '<p class="mute">Letzter Website-Import: <b>' . h($wl) . '</b> · ' . h($webJob['created_at'])
        . ($webJob['note'] ? ' · ' . h($webJob['note']) : '') . ' <span class="mute">(Details: web.log)</span></p>';
    if (in_array($webJob['status'], ['pending', 'running'], true)) {
        echo '<meta http-equiv="refresh" content="5">';
    }
}

$job = $pdo->query("SELECT * FROM jobs WHERE type IN ('crawl','crawl_full') ORDER BY id DESC LIMIT 1")->fetch();
if ($job) {
    if (in_array($job['status'], ['pending', 'running'], true)) {
        echo '<meta http-equiv="refresh" content="5">'; // Status automatisch aktualisieren
    }
    $label = ['pending' => 'wartet auf den Bot', 'running' => 'läuft', 'done' => 'abgeschlossen', 'failed' => 'fehlgeschlagen'][$job['status']] ?? $job['status'];
    echo '<p class="mute">Letzte Durchsuchung #' . (int) $job['id'] . ': <b>' . h($label) . '</b> · angefordert ' . h($job['created_at'])
        . ($job['finished_at'] ? ' · beendet ' . h($job['finished_at']) : '') . ($job['note'] ? ' · ' . h($job['note']) : '') . '</p>';
}
echo '</div>';

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

// ------------------------------------------------------------ Gefahrenbereich
$cInfo = (int) $pdo->query('SELECT COUNT(*) FROM server_info')->fetchColumn();
$cKnow = (int) $pdo->query("SELECT COUNT(*) FROM knowledge WHERE source = 'crawl'")->fetchColumn();
$cState = (int) $pdo->query('SELECT COUNT(*) FROM crawl_channels')->fetchColumn();

echo '<div class="card" style="border-color:var(--bad)"><h2 style="color:var(--bad)">⚠ Gefahrenbereich: Durchsuchte Inhalte löschen</h2>'
    . '<div class="flash err"><b>Achtung:</b> Das Löschen ist <b>endgültig</b> und kann nicht rückgängig gemacht werden. '
    . 'Der Bot verliert das daraus gelernte Wissen und kann entsprechende Fragen nicht mehr beantworten, '
    . 'bis der Server erneut durchsucht wurde.</div>'
    . '<form method="post" onsubmit="return confirm(\'ENDGÜLTIG löschen? Das kann nicht rückgängig gemacht werden.\')">' . csrf_field()
    . '<input type="hidden" name="action" value="purge">'
    . '<label style="color:var(--text)"><input type="checkbox" name="purge_info" value="1" style="width:auto"> '
    . 'Alle gespeicherten Server-Infos (' . $cInfo . ')</label>'
    . '<label style="color:var(--text)"><input type="checkbox" name="purge_knowledge" value="1" style="width:auto"> '
    . 'Aus der Durchsuchung gelernte Frage/Antwort-Paare (' . $cKnow . ') <span class="mute">– im Chat gelernte und manuell '
    . 'angelegte Antworten bleiben erhalten</span></label>'
    . '<label style="color:var(--text)"><input type="checkbox" name="purge_state" value="1" style="width:auto"> '
    . 'Durchsuchungs-Stand zurücksetzen (' . $cState . ' Channels) <span class="mute">– die nächste Durchsuchung liest dann wieder '
    . 'alles von vorn</span></label>'
    . '<label>Zur Bestätigung <b>LÖSCHEN</b> eingeben</label><input name="confirm" autocomplete="off" placeholder="LÖSCHEN" style="max-width:240px">'
    . '<p><button class="del">Ausgewählte Inhalte endgültig löschen</button></p></form>'
    . '<p class="mute">Hinweis: Einträge, die vor dieser Funktion durchsucht wurden, lassen sich nicht von im Chat gelernten '
    . 'unterscheiden und bleiben unter „Wissen“ erhalten – dort einzeln löschbar.</p></div>';
page_footer();
