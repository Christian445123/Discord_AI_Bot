<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Text;

require_login();
$pdo = db();

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $question = trim((string) ($_POST['question'] ?? ''));
    $answer = trim((string) ($_POST['answer'] ?? ''));

    if ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM knowledge WHERE id = ?')->execute([$id]);
        flash('Eintrag gelöscht.');
    } elseif (in_array($action, ['add', 'save'], true)) {
        $norm = Text::normalize($question);
        if ($norm === '' || $answer === '') {
            flash('Frage und Antwort dürfen nicht leer sein.');
        } elseif ($action === 'add') {
            $pdo->prepare("INSERT INTO knowledge (question, normalized, answer, answered_by, channel_id, source, created_at) VALUES (?,?,?,?,?,'panel',?)")
                ->execute([$question, $norm, $answer, 'panel:' . $_SESSION['user'], 'panel', date('Y-m-d H:i:s')]);
            flash('Eintrag hinzugefügt.');
        } else {
            $pdo->prepare('UPDATE knowledge SET question = ?, normalized = ?, answer = ?, score = ? WHERE id = ?')
                ->execute([$question, $norm, $answer, (int) ($_POST['score'] ?? 0), $id]);
            flash('Eintrag gespeichert.');
        }
    }
    header('Location: knowledge.php');
    exit;
}

// ------------------------------------------------------------ Bearbeiten
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM knowledge WHERE id = ?');
    $st->execute([$editId]);
    $row = $st->fetch();
    if ($row) {
        page_header('Eintrag bearbeiten', 'knowledge.php');
        echo '<h1>Eintrag #' . (int) $row['id'] . ' bearbeiten</h1><div class="card"><form method="post">'
            . csrf_field() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int) $row['id'] . '">'
            . '<label>Frage</label><textarea name="question" required>' . h($row['question']) . '</textarea>'
            . '<label>Antwort</label><textarea name="answer" required>' . h($row['answer']) . '</textarea>'
            . '<label>Bewertung (negativ = wird ab -3 nicht mehr genutzt)</label><input type="number" name="score" value="' . (int) $row['score'] . '">'
            . '<p class="mute">Genutzt: ' . (int) $row['uses'] . 'x · Erstellt: ' . h($row['created_at']) . ' · Von: ' . h($row['answered_by']) . '</p>'
            . '<p><button>Speichern</button> <a class="btn sec" href="knowledge.php">Abbrechen</a></p></form></div>';
        page_footer();
        exit;
    }
}

// ------------------------------------------------------------ Liste
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 25;
$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE question LIKE ? OR answer LIKE ?';
    $params = ['%' . $q . '%', '%' . $q . '%'];
}
$total = $pdo->prepare("SELECT COUNT(*) FROM knowledge $where");
$total->execute($params);
$total = (int) $total->fetchColumn();

$st = $pdo->prepare("SELECT * FROM knowledge $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$rows = $st->fetchAll();

page_header('Wissen', 'knowledge.php');
echo '<h1>Gelerntes Wissen (' . $total . ')</h1>';

echo '<div class="card"><h2>Neuen Eintrag hinzufügen</h2><form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="add">'
    . '<label>Frage</label><textarea name="question" required></textarea>'
    . '<label>Antwort</label><textarea name="answer" required></textarea>'
    . '<p><button>Hinzufügen</button></p></form></div>';

echo '<div class="card"><form method="get" class="row"><div><label>Suche</label><input name="q" value="' . h($q) . '"></div><button>Suchen</button></form></div>';

echo '<div class="card"><table><tr><th>Frage</th><th>Antwort</th><th>Nutzung</th><th>Bew.</th><th></th></tr>';
foreach ($rows as $r) {
    echo '<tr><td>' . h(mb_strimwidth($r['question'], 0, 120, '…')) . '</td><td>' . h(mb_strimwidth($r['answer'], 0, 120, '…'))
        . '</td><td>' . (int) $r['uses'] . '</td><td>' . (int) $r['score'] . '</td><td style="white-space:nowrap">'
        . '<a class="btn sec" href="knowledge.php?edit=' . (int) $r['id'] . '">Bearbeiten</a> '
        . '<form method="post" class="inline" onsubmit="return confirm(\'Wirklich löschen?\')">' . csrf_field()
        . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
        . '<button class="del">Löschen</button></form></td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="5" class="mute">Keine Einträge.</td></tr>';
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
