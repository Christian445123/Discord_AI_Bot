<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_login();

/**
 * Die Einstellungen werden automatisch aus der .env.example erzeugt: jede dort stehende Einstellung erscheint hier,
 * mit ihrem Kommentar als Beschreibung. Neue Einstellungen aus kuenftigen Updates sind damit sofort verfuegbar.
 */

/** Zugangsdaten - bewusst nicht im Panel aenderbar (nur in der .env bzw. per install.php). */
$secret = ['DISCORD_TOKEN', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PANEL_PASSWORD_HASH', 'WEB_TRIGGER_TOKEN', 'WEB_EXTRA_HEADER'];
/** Schalter, die unter "Steuerung" sofort (ohne Neustart) gelten - hier waere der Wert wirkungslos. */
$managedInControl = ['ANSWERS_ENABLED', 'LEARNING_ENABLED', 'MODERATION_ENABLED', 'FALLBACK_ENABLED', 'CRAWL_AUTO_ENABLED',
    'TEST_MODE', 'MENTION_PROTECTION_ENABLED', 'CRAWL_INTERVAL_HOURS', 'WEB_AUTO_ENABLED', 'WEB_INTERVAL_HOURS'];
$selects = [
    'ADDRESS_FORM' => ['du', 'sie'],
    'LOG_LEVEL' => ['error', 'warning', 'info', 'debug'],
];

/** @return array<int,array{title:string,fields:array<string,array{label:string,type:string,default:string}>}> */
function parse_example(string $path): array
{
    $sections = [['title' => 'Allgemein', 'fields' => []]];
    $comment = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $t = trim($line);
        if ($t === '') {
            $comment = [];
            continue;
        }
        if ($t[0] === '#') {
            $text = trim(ltrim($t, '#'));
            if (preg_match('/^-{2,}\s*(.+?)\s*-{2,}$/u', $text, $m)) {
                $sections[] = ['title' => $m[1], 'fields' => []];
                $comment = [];
            } elseif ($text !== '' && !str_starts_with($text, '====') && !str_starts_with($text, 'Diese Datei') && !str_starts_with($text, 'Discord KI-Bot')
                && !str_starts_with($text, 'Die ".env"')) {
                $comment[] = $text;
            }
            continue;
        }
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $t, $m)) {
            $def = trim($m[2], "'\"");
            $type = in_array(strtolower($def), ['true', 'false'], true) ? 'bool'
                : (ctype_digit($def) ? 'number' : (preg_match('/^\d+\.\d+$/', $def) ? 'float' : 'text'));
            if (preg_match('/_IDS?$/', $m[1])) {
                $type = 'ids';
            }
            $sections[count($sections) - 1]['fields'][$m[1]] = ['label' => implode(' ', $comment), 'type' => $type, 'default' => $def];
            $comment = [];
        }
    }
    return array_values(array_filter($sections, fn($s) => $s['fields'] !== []));
}

$sections = parse_example(dirname(__DIR__) . '/.env.example');

// Erlaubte Felder (Whitelist fuer das Speichern)
$allowed = [];
foreach ($sections as $s) {
    foreach ($s['fields'] as $key => $f) {
        if (!in_array($key, $secret, true) && !in_array($key, $managedInControl, true)) {
            $allowed[$key] = $f;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = [];
    foreach ($allowed as $key => $f) {
        $v = str_replace(["\r", "\n"], '', trim((string) ($_POST[$key] ?? '')));
        $bad = ($f['type'] === 'number' && $v !== '' && !ctype_digit($v))
            || ($f['type'] === 'float' && $v !== '' && !is_numeric($v))
            || ($f['type'] === 'bool' && !in_array($v, ['true', 'false'], true))
            || ($f['type'] === 'ids' && !preg_match('/^[0-9,\s]*$/', $v))
            || (isset($selects[$key]) && !in_array($v, $selects[$key], true));
        if ($bad) {
            flash("Ungültiger Wert bei: $key");
            header('Location: settings.php');
            exit;
        }
        $new[$key] = $f['type'] === 'ids' ? preg_replace('/\s+/', '', $v) : $v;
    }
    if (isset($_POST['new_password']) && $_POST['new_password'] !== '') {
        if (strlen($_POST['new_password']) < 8) {
            flash('Das Passwort muss mindestens 8 Zeichen lang sein.');
            header('Location: settings.php');
            exit;
        }
        $new['PANEL_PASSWORD_HASH'] = password_hash((string) $_POST['new_password'], PASSWORD_DEFAULT);
    }
    env_update($new);
    flash('Gespeichert. Damit der Bot die Änderungen übernimmt, muss er neu gestartet werden (pm2 restart discord-ai-bot).');
    header('Location: settings.php');
    exit;
}

// .env frisch einlesen (Dotenv-Werte sind pro Request fest)
$current = [];
foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
        $v = trim($m[2]);
        if (strlen($v) >= 2 && ($v[0] === "'" || $v[0] === '"') && $v[-1] === $v[0]) {
            $v = stripcslashes(substr($v, 1, -1));
        }
        $current[$m[1]] = $v;
    }
}

page_header('Einstellungen', 'settings.php');
echo '<h1>Einstellungen</h1>'
    . '<p class="mute">Alle Einstellungen der <code>.env</code>. Änderungen wirken nach einem Neustart des Bots. '
    . 'Schalter wie Testmodus, Lernen oder Markierungs-Schutz stehen unter <a href="control.php">Steuerung</a> und gelten sofort.</p>'
    . '<form method="post">' . csrf_field();

foreach ($sections as $s) {
    $fields = array_filter($s['fields'], fn($k) => isset($allowed[$k]), ARRAY_FILTER_USE_KEY);
    if ($fields === []) {
        continue;
    }
    echo '<div class="card"><h2>' . h($s['title']) . '</h2>';
    foreach ($fields as $key => $f) {
        $val = $current[$key] ?? $f['default'];
        echo '<label>' . h($key) . '</label>';
        if ($f['type'] === 'bool' || isset($selects[$key])) {
            $opts = $f['type'] === 'bool' ? ['true', 'false'] : $selects[$key];
            echo '<select name="' . h($key) . '">';
            foreach ($opts as $opt) {
                echo '<option' . ($opt === strtolower($val) ? ' selected' : '') . '>' . h($opt) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input name="' . h($key) . '" type="' . ($f['type'] === 'number' ? 'number' : 'text') . '" value="' . h($val) . '">';
        }
        if ($f['label'] !== '') {
            echo '<div class="mute" style="margin-top:4px">' . h($f['label']) . '</div>';
        }
    }
    echo '</div>';
}

echo '<div class="card"><h2>Panel-Zugang</h2><label>Neues Panel-Passwort (leer lassen = unverändert)</label>'
    . '<input type="password" name="new_password" autocomplete="new-password"></div>'
    . '<p><button>Speichern</button></p></form>'
    . '<p class="mute">Token und Datenbank-Zugang werden aus Sicherheitsgründen nur in der .env-Datei bzw. per install.php geändert.</p>';
page_footer();
