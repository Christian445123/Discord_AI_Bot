<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_login();

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

$hb = $pdo->query("SELECT updated_at FROM bot_status WHERE k = 'heartbeat'")->fetchColumn();
$online = $hb && (time() - strtotime((string) $hb)) < 30;

page_header('Dashboard', 'dashboard.php');
echo '<h1>Dashboard</h1><div class="grid">';
echo '<div class="card"><div class="stat" style="color:var(--' . ($online ? 'ok' : 'bad') . ')">' . ($online ? 'Online' : 'Offline')
    . '</div><div class="mute">Bot-Status</div></div>';
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
