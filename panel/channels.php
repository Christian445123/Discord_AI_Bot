<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_login();
$pdo = db();

// ------------------------------------------------------------ Speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cats = array_map('strval', (array) ($_POST['cat'] ?? []));
    $chans = array_map('strval', (array) ($_POST['chan'] ?? []));

    // nur bekannte IDs zulassen
    $known = [];
    foreach ($pdo->query('SELECT channel_id, kind FROM discord_channels') as $r) {
        $known[$r['channel_id']] = $r['kind'];
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM crawl_exclusions');
    $ins = $pdo->prepare('INSERT INTO crawl_exclusions (target_id, kind, created_at) VALUES (?,?,NOW())');
    $n = 0;
    foreach ($cats as $id) {
        if (($known[$id] ?? '') === 'category') {
            $ins->execute([$id, 'category']);
            $n++;
        }
    }
    foreach ($chans as $id) {
        if (($known[$id] ?? '') === 'channel') {
            $ins->execute([$id, 'channel']);
            $n++;
        }
    }
    $pdo->commit();
    flash("Gespeichert: $n Ausschlüsse. Gilt ab der nächsten Durchsuchung.");
    header('Location: channels.php');
    exit;
}

// ------------------------------------------------------------ Daten
$rows = $pdo->query('SELECT * FROM discord_channels ORDER BY position, name')->fetchAll();
$excluded = [];
foreach ($pdo->query('SELECT target_id FROM crawl_exclusions') as $r) {
    $excluded[$r['target_id']] = true;
}

$cats = [];
$byParent = [];
foreach ($rows as $r) {
    if ($r['kind'] === 'category') {
        $cats[$r['channel_id']] = $r;
    } else {
        $byParent[(string) $r['parent_id']][] = $r;
    }
}

page_header('Channels', 'channels.php');
echo '<h1>Channels von der Durchsuchung ausnehmen</h1>';
echo '<p class="mute">Haken setzen = wird <b>nicht</b> gelesen. Ein Haken an einer Kategorie nimmt alle Channels darin aus. '
    . 'Channels, die nur für das Team sichtbar sind, werden ohnehin automatisch übersprungen (gesperrt dargestellt).</p>';

if (!$rows) {
    echo '<div class="card mute">Noch keine Channels bekannt. Der Bot muss laufen (er trägt die Channels beim Start ein) '
        . 'und es muss die <code>GUILD_ID</code> stimmen.</div>';
    page_footer();
    exit;
}

function channel_row(array $c, bool $catExcluded, array $excluded): void
{
    $auto = $c['auto_reason'] !== '';
    $checked = isset($excluded[$c['channel_id']]) || $catExcluded;
    echo '<label class="ch' . ($auto ? ' auto' : '') . '"><input type="checkbox" name="chan[]" value="' . h($c['channel_id']) . '"'
        . ($checked ? ' checked' : '') . ($auto ? ' disabled' : '') . ($catExcluded ? ' data-lock="1"' : '') . '> #' . h($c['name'])
        . ($auto ? ' <span class="mute">– automatisch ausgeschlossen: ' . h($c['auto_reason']) . '</span>' : '') . '</label>';
}

echo '<style>.cat{border:1px solid var(--line);border-radius:8px;margin-bottom:12px;overflow:hidden}'
    . '.cat>.head{background:var(--bg);padding:10px 12px;font-weight:600;display:flex;gap:8px;align-items:center}'
    . '.ch{display:flex;gap:8px;align-items:center;padding:8px 12px 8px 32px;border-top:1px solid var(--line);margin:0;color:var(--text);font-size:15px}'
    . '.ch.auto{opacity:.55}input[type=checkbox]{width:auto}</style>';

echo '<form method="post">' . csrf_field();

foreach ($cats as $cid => $cat) {
    $cid = (string) $cid; // numerische Schluessel sind in PHP Integer -> fuer h() zurueck in String
    $catEx = isset($excluded[$cid]);
    echo '<div class="cat"><label class="head"><input type="checkbox" class="catbox" name="cat[]" value="' . h($cid) . '"'
        . ($catEx ? ' checked' : '') . '> ' . h(mb_strtoupper($cat['name'])) . '</label>';
    foreach ($byParent[$cid] ?? [] as $c) {
        channel_row($c, false, $excluded);
    }
    echo '</div>';
}

// Channels ohne Kategorie
if (!empty($byParent[''])) {
    echo '<div class="cat"><div class="head">OHNE KATEGORIE</div>';
    foreach ($byParent[''] as $c) {
        channel_row($c, false, $excluded);
    }
    echo '</div>';
}

echo '<p><button>Speichern</button></p></form>';

// Kategorie-Haken markiert/sperrt alle Channels darin
echo '<script>
document.querySelectorAll(".cat").forEach(function(cat){
  var head = cat.querySelector(".catbox"); if(!head) return;
  var kids = cat.querySelectorAll("input[name=\'chan[]\']:not([disabled])");
  var saved = Array.prototype.map.call(kids, function(k){return k.checked;});
  function apply(){
    kids.forEach(function(k,i){
      if(head.checked){ k.checked = true; k.disabled = true; }
      else { k.disabled = false; k.checked = saved[i]; }
    });
  }
  head.addEventListener("change", apply);
  kids.forEach(function(k,i){ k.addEventListener("change", function(){ saved[i] = k.checked; }); });
  if(head.checked){ kids.forEach(function(k){ k.disabled = true; }); }
});
// disabled-Checkboxen werden nicht gesendet: vor dem Absenden freischalten (Kategorie-Ausschluss gilt ohnehin)
document.querySelector("form").addEventListener("submit", function(){
  document.querySelectorAll(".cat").forEach(function(cat){
    var head = cat.querySelector(".catbox");
    if(head && head.checked){
      cat.querySelectorAll("input[name=\'chan[]\']").forEach(function(k){ k.checked = false; k.disabled = false; });
    }
  });
});
</script>';
page_footer();
