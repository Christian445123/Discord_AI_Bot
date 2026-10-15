<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Env;

require_login();
$pdo = db();

/** Schalter (true/false), die sofort - ohne Neustart - im Bot gelten. */
$toggles = [
    'ANSWERS_ENABLED' => ['Fragen beantworten', 'Der Bot antwortet auf Fragen, wenn kein Teamler geantwortet hat.', true],
    'LEARNING_ENABLED' => ['Von Teamlern lernen', 'Antworten von Teamlern werden als Wissen gespeichert.', true],
    'MODERATION_ENABLED' => ['Channel-Überwachung', 'Der Bot greift bei Streit/Beleidigungen deeskalierend ein.', true],
    'FALLBACK_ENABLED' => ['Hinweis bei unbekannter Frage', 'Freundlicher Hinweis, wenn der Bot keine Antwort kennt.', true],
    'CRAWL_AUTO_ENABLED' => ['Automatische Durchsuchung', 'Der Bot durchsucht den Server regelmäßig selbst (Intervall siehe unten).', false],
    'TEST_MODE' => ['Testmodus', 'Teamler werden NICHT ignoriert: Ihre Nachrichten werden wie Spielernachrichten behandelt '
        . '(Fragen werden beantwortet, Überwachung greift, kurze Wartezeit). Es wird nichts gelernt, Antworten sind mit „Testmodus“ gekennzeichnet.', false],
];

/** Aktionen, die vom Bot ausgefuehrt werden. */
$actions = [
    'crawl' => ['Server jetzt durchsuchen', 'Liest die Channel-Verläufe und speichert Wissen.'],
    'sync_channels' => ['Channels synchronisieren', 'Aktualisiert die Kategorie-/Channel-Liste im Panel.'],
    'update_check' => ['Auf Updates prüfen', 'Holt den Stand von GitHub (Installation unter „Updates“).'],
    'restart' => ['Bot neu starten', 'Startet den Bot neu (pm2 startet ihn sofort wieder).'],
];
$typeLabels = [
    'crawl' => 'Server durchsuchen', 'sync_channels' => 'Channels synchronisieren', 'update_check' => 'Update-Prüfung',
    'update_apply' => 'Update installieren', 'restart' => 'Neustart',
];

function setting_value(PDO $pdo, string $key, string $default): string
{
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v !== false ? (string) $v : Env::get($key, $default);
}

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $up = $pdo->prepare('INSERT INTO settings (k, v, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()');
        foreach (array_keys($toggles) as $key) {
            $up->execute([$key, isset($_POST[$key]) ? 'true' : 'false']);
        }
        $hours = max(1, min(720, (int) ($_POST['CRAWL_INTERVAL_HOURS'] ?? 24)));
        $up->execute(['CRAWL_INTERVAL_HOURS', (string) $hours]);
        flash('Gespeichert. Die Einstellungen gelten im Bot innerhalb weniger Sekunden.');
    } elseif (isset($actions[$action])) {
        $open = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE type = ? AND status IN ('pending','running')");
        $open->execute([$action]);
        if ((int) $open->fetchColumn() > 0) {
            flash('Dieser Auftrag läuft bzw. wartet bereits.');
        } else {
            $pdo->prepare("INSERT INTO jobs (type, status, created_at) VALUES (?, 'pending', NOW())")->execute([$action]);
            flash('Auftrag „' . $actions[$action][0] . '“ angelegt. Der Bot führt ihn innerhalb weniger Sekunden aus.');
        }
    }
    header('Location: control.php');
    exit;
}

// ------------------------------------------------------------ Anzeige
$hb = $pdo->query("SELECT updated_at FROM bot_status WHERE k = 'heartbeat'")->fetchColumn();
$online = $hb && (time() - strtotime((string) $hb)) < 30;
$jobs = $pdo->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 10')->fetchAll();
$hasOpen = false;
foreach ($jobs as $j) {
    if (in_array($j['status'], ['pending', 'running'], true)) {
        $hasOpen = true;
    }
}

page_header('Steuerung', 'control.php');
echo '<h1>Steuerung</h1>';
if ($hasOpen) {
    echo '<meta http-equiv="refresh" content="5">';
}

echo '<div class="card"><p style="margin:0">Bot: ' . ($online
    ? '<b style="color:var(--ok)">online</b>'
    : '<b style="color:var(--bad)">offline</b> <span class="mute">– Aufträge werden erst ausgeführt, wenn der Bot läuft (pm2)</span>') . '</p></div>';

// Funktionen starten
echo '<div class="card"><h2>Funktionen starten</h2><div class="grid" style="margin:0">';
foreach ($actions as $key => [$label, $desc]) {
    $confirm = $key === 'restart' ? ' onsubmit="return confirm(\'Bot wirklich neu starten?\')"' : '';
    echo '<form method="post" class="card" style="margin:0"' . $confirm . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . h($key) . '"><b>' . h($label) . '</b>'
        . '<p class="mute" style="margin:6px 0 12px">' . h($desc) . '</p><button' . ($online ? '' : ' disabled') . '>Starten</button></form>';
}
echo '</div><p class="mute" style="margin-bottom:0">Update installieren, Channels ausnehmen und Löschen: siehe '
    . '<a href="updates.php">Updates</a>, <a href="channels.php">Channels</a>, <a href="server_info.php">Server-Infos</a>.</p></div>';

// Schalter
echo '<form method="post" class="card"><h2>Funktionen ein-/ausschalten</h2>' . csrf_field() . '<input type="hidden" name="action" value="save">';
foreach ($toggles as $key => [$label, $desc, $def]) {
    $on = strtolower(setting_value($pdo, $key, $def ? 'true' : 'false')) === 'true';
    echo '<label style="display:flex;gap:10px;align-items:flex-start;color:var(--text);margin:12px 0">'
        . '<input type="checkbox" name="' . h($key) . '" value="1" style="width:auto;margin-top:4px"' . ($on ? ' checked' : '') . '>'
        . '<span><b>' . h($label) . '</b>' . ($key === 'TEST_MODE' ? ' <span style="color:#f0b232">🧪</span>' : '')
        . '<br><span class="mute">' . h($desc) . '</span></span></label>';
}
echo '<label>Intervall der automatischen Durchsuchung (Stunden)</label><input type="number" name="CRAWL_INTERVAL_HOURS" min="1" max="720" style="max-width:160px" value="'
    . (int) setting_value($pdo, 'CRAWL_INTERVAL_HOURS', '24') . '">'
    . '<p><button>Speichern</button></p></form>';

// Letzte Auftraege
echo '<div class="card"><h2>Letzte Aufträge</h2><table><tr><th>#</th><th>Auftrag</th><th>Status</th><th>Angelegt</th><th>Hinweis</th></tr>';
$statusLabel = ['pending' => 'wartet', 'running' => 'läuft', 'done' => 'abgeschlossen', 'failed' => 'fehlgeschlagen'];
foreach ($jobs as $j) {
    echo '<tr><td>' . (int) $j['id'] . '</td><td>' . h($typeLabels[$j['type']] ?? $j['type']) . '</td><td>'
        . h($statusLabel[$j['status']] ?? $j['status']) . '</td><td class="mute">' . h($j['created_at']) . '</td><td class="mute">'
        . h($j['note']) . '</td></tr>';
}
if (!$jobs) {
    echo '<tr><td colspan="5" class="mute">Noch keine Aufträge.</td></tr>';
}
echo '</table></div>';
page_footer();
