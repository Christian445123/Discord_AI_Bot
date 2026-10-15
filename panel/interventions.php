<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_login();
$pdo = db();

$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 50;
$total = (int) $pdo->query('SELECT COUNT(*) FROM interventions')->fetchColumn();
$rows = $pdo->query('SELECT * FROM interventions ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per))->fetchAll();

page_header('Eingriffe', 'interventions.php');
echo '<h1>Channel-Eingriffe (' . $total . ')</h1><div class="card"><table>'
    . '<tr><th>Zeit</th><th>Channel-ID</th><th>Beteiligte (User-IDs)</th><th>Hitze</th></tr>';
foreach ($rows as $r) {
    echo '<tr><td class="mute">' . h($r['created_at']) . '</td><td>' . h($r['channel_id']) . '</td><td>'
        . h(str_replace(',', ', ', $r['user_ids'])) . '</td><td>' . (int) $r['heat'] . '</td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="4" class="mute">Noch keine Eingriffe.</td></tr>';
}
echo '</table><div class="pager">';
if ($page > 1) {
    echo '<a class="btn sec" href="?p=' . ($page - 1) . '">« Zurück</a>';
}
if ($page * $per < $total) {
    echo '<a class="btn sec" href="?p=' . ($page + 1) . '">Weiter »</a>';
}
echo '</div></div>';
page_footer();
