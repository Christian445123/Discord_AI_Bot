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
    $type = ['check' => 'update_check', 'apply' => 'update_apply'][$action] ?? null;

    if ($type !== null) {
        $open = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE type IN ('update_check','update_apply') AND status IN ('pending','running')")->fetchColumn();
        if ($open > 0) {
            flash('Es läuft bzw. wartet bereits ein Update-Auftrag.');
        } else {
            $pdo->prepare("INSERT INTO jobs (type, status, created_at) VALUES (?, 'pending', NOW())")->execute([$type]);
            flash($action === 'check'
                ? 'Prüfung angefordert. Der Bot führt sie innerhalb weniger Sekunden aus.'
                : 'Update angefordert. Der Bot installiert es und startet danach neu.');
        }
    }
    header('Location: updates.php');
    exit;
}

// ------------------------------------------------------------ Daten
$hb = $pdo->query("SELECT updated_at FROM bot_status WHERE k = 'heartbeat'")->fetchColumn();
$online = $hb && (time() - strtotime((string) $hb)) < 30;

$raw = $pdo->query("SELECT v FROM bot_status WHERE k = 'update_info'")->fetchColumn();
$info = $raw ? json_decode((string) $raw, true) : null;

$job = $pdo->query("SELECT * FROM jobs WHERE type IN ('update_check','update_apply') ORDER BY id DESC LIMIT 1")->fetch();
$busy = $job && in_array($job['status'], ['pending', 'running'], true);

page_header('Updates', 'updates.php');
echo '<h1>Updates von GitHub</h1>';

echo '<div class="card"><p>Bot: ' . ($online
    ? '<b style="color:var(--ok)">online</b>'
    : '<b style="color:var(--bad)">offline</b> <span class="mute">– Aufträge werden erst ausgeführt, wenn der Bot läuft (pm2)</span>') . '</p>';

if ($info) {
    $behind = (int) $info['behind'];
    echo '<table><tr><th style="width:180px">Installierter Stand</th><td><code>' . h($info['current']['hash']) . '</code> '
        . h($info['current']['subject']) . ' <span class="mute">(' . h($info['current']['date']) . ')</span></td></tr>'
        . '<tr><th>Branch</th><td>' . h($info['branch']) . ' <span class="mute">→ ' . h($info['upstream']) . '</span></td></tr>'
        . '<tr><th>Zuletzt geprüft</th><td>' . h($info['checked_at']) . '</td></tr>'
        . '<tr><th>Status</th><td>' . ($behind > 0
            ? '<b style="color:var(--bad)">' . $behind . ' neue Änderung(en) verfügbar</b>'
            : '<b style="color:var(--ok)">Aktuell</b>') . '</td></tr></table>';

    if (!empty($info['dirty'])) {
        echo '<div class="flash err" style="margin-top:12px"><b>Lokale Änderungen an versionierten Dateien:</b><br>'
            . h(implode(', ', array_slice($info['dirty'], 0, 10))) . '<br>Das Update wird in diesem Zustand abgebrochen.</div>';
    }
} else {
    echo '<p class="mute">Noch nicht geprüft. Klicke auf „Auf Updates prüfen“.</p>';
}

echo '<div class="row" style="margin-top:14px;gap:10px"><form method="post" class="inline">' . csrf_field()
    . '<input type="hidden" name="action" value="check"><button class="sec"' . ($busy || !$online ? ' disabled' : '') . '>Auf Updates prüfen</button></form>';
if ($info && (int) $info['behind'] > 0) {
    echo '<form method="post" class="inline" onsubmit="return confirm(\'Update jetzt installieren? Der Bot wird dabei neu gestartet.\')">'
        . csrf_field() . '<input type="hidden" name="action" value="apply"><button' . ($busy || !$online || !empty($info['dirty']) ? ' disabled' : '')
        . '>Update installieren</button></form>';
}
echo '</div>';

if ($job) {
    $label = ['pending' => 'wartet auf den Bot', 'running' => 'läuft', 'done' => 'abgeschlossen', 'failed' => 'fehlgeschlagen'][$job['status']] ?? $job['status'];
    $what = $job['type'] === 'update_apply' ? 'Update' : 'Prüfung';
    echo '<p class="mute" style="margin-top:12px">Letzter Auftrag (' . $what . ' #' . (int) $job['id'] . '): <b>' . h($label) . '</b> · '
        . h($job['created_at']) . ($job['note'] ? ' · ' . h($job['note']) : '') . '</p>';
    if ($busy) {
        echo '<meta http-equiv="refresh" content="5">';
    }
}
echo '</div>';

if ($info && !empty($info['commits'])) {
    echo '<div class="card"><h2>Neue Änderungen</h2><table><tr><th>Commit</th><th>Beschreibung</th><th>Autor</th><th>Datum</th></tr>';
    foreach ($info['commits'] as $c) {
        echo '<tr><td><code>' . h($c['hash']) . '</code></td><td>' . h($c['subject']) . '</td><td>' . h($c['author'])
            . '</td><td class="mute">' . h($c['date']) . '</td></tr>';
    }
    echo '</table></div>';
}

$logFile = $root . '/update.log';
if (is_file($logFile)) {
    $tail = implode("\n", array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -20));
    echo '<div class="card"><h2>Letzte Ausgabe</h2><pre style="white-space:pre-wrap;margin:0" class="mute">' . h($tail) . '</pre></div>';
}

echo '<p class="mute">Die <code>.env</code> (Zugangsdaten) ist nicht im Repository und wird durch Updates nie verändert. '
    . 'Neue Tabellen legt der Bot beim Neustart automatisch an.</p>';
page_footer();
