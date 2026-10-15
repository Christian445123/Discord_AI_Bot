<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Env;

require_login();

/** Nur diese Schluessel sind im Panel aenderbar - Token und DB-Zugang bewusst nicht. */
$fields = [
    'SERVER_NAME' => ['Servername', 'text'],
    'ADDRESS_FORM' => ['Anrede', 'select:du,sie'],
    'TEAM_ROLE_IDS' => ['Team-Rollen-IDs (kommagetrennt)', 'text'],
    'MOD_ROLE_ID' => ['Rolle für Eskalationen', 'text'],
    'LOG_CHANNEL_ID' => ['Log-Channel-ID', 'text'],
    'QUESTION_CHANNEL_IDS' => ['Support-Channel-IDs (leer = alle)', 'text'],
    'ANSWER_DELAY_SECONDS' => ['Wartezeit vor Bot-Antwort (Sek.)', 'number'],
    'MIN_CONFIDENCE' => ['Mindest-Sicherheit (0.0 - 1.0)', 'text'],
    'LEARN_WINDOW_SECONDS' => ['Lernfenster (Sek.)', 'number'],
    'MODERATION_IGNORE_CHANNEL_IDS' => ['Nicht überwachte Channel-IDs', 'text'],
    'HEAT_THRESHOLD' => ['Eingriffs-Schwelle', 'number'],
    'INTERVENTION_COOLDOWN_SECONDS' => ['Pause zwischen Eingriffen (Sek.)', 'number'],
    'EXTRA_BAD_WORDS' => ['Zusätzliche Reizwörter (kommagetrennt)', 'text'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = [];
    foreach ($fields as $key => [$label, $type]) {
        $v = trim((string) ($_POST[$key] ?? ''));
        $v = str_replace(["\r", "\n"], '', $v);
        if ($type === 'number' && $v !== '' && !ctype_digit($v)) {
            flash("Ungültiger Wert bei: $label");
            header('Location: settings.php');
            exit;
        }
        $new[$key] = $v;
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
    flash('Gespeichert. Damit der Bot die Änderungen übernimmt, muss er neu gestartet werden.');
    header('Location: settings.php');
    exit;
}

// .env frisch einlesen (Dotenv-Werte sind immutable pro Request)
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
echo '<h1>Einstellungen</h1><div class="card"><form method="post">' . csrf_field();
foreach ($fields as $key => [$label, $type]) {
    $val = $current[$key] ?? '';
    echo '<label>' . h($label) . ' <span class="mute">(' . h($key) . ')</span></label>';
    if (str_starts_with($type, 'select:')) {
        echo '<select name="' . h($key) . '">';
        foreach (explode(',', substr($type, 7)) as $opt) {
            echo '<option' . ($opt === $val ? ' selected' : '') . '>' . h($opt) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input name="' . h($key) . '" type="' . ($type === 'number' ? 'number' : 'text') . '" value="' . h($val) . '">';
    }
}
echo '<label>Neues Panel-Passwort (leer lassen = unverändert)</label><input type="password" name="new_password" autocomplete="new-password">'
    . '<p><button>Speichern</button></p></form></div>'
    . '<p class="mute">Token und Datenbank-Zugang werden aus Sicherheitsgründen nur in der .env-Datei bzw. per install.php geändert.</p>';
page_footer();
