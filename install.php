<?php
/**
 * Ein-Skript-Installation: php install.php
 *
 * - prueft PHP-Version und Erweiterungen
 * - laedt Composer bei Bedarf selbst herunter und installiert die Abhaengigkeiten
 * - fragt alle Einstellungen ab und schreibt die .env (LIVE-Datei)
 * - prueft den Discord-Token, legt die Datenbank und Tabellen an
 * - startet den Bot auf Wunsch direkt
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Bitte per Konsole ausfuehren: php install.php\n");
}

const ROOT_MIN_PHP = '8.1.0';
$root = __DIR__;
chdir($root);

function say(string $s = ''): void { echo $s . PHP_EOL; }
function ok(string $s): void { say("  [OK]   $s"); }
function warn(string $s): void { say("  [!]    $s"); }
function fail(string $s): never { fwrite(STDERR, "  [FEHLER] $s" . PHP_EOL); exit(1); }

function ask(string $label, string $default = '', bool $required = false): string
{
    while (true) {
        $suffix = $default !== '' ? " [$default]" : '';
        echo "  $label$suffix: ";
        $line = fgets(STDIN);
        if ($line === false) {
            fail('Keine Eingabe moeglich (STDIN geschlossen).');
        }
        $line = trim($line);
        if ($line === '') {
            $line = $default;
        }
        if ($line !== '' || !$required) {
            return $line;
        }
        warn('Dieses Feld ist erforderlich.');
    }
}

function askYesNo(string $label, bool $default = true): bool
{
    $a = strtolower(ask($label . ($default ? ' (J/n)' : ' (j/N)')));
    if ($a === '') {
        return $default;
    }
    return in_array($a, ['j', 'ja', 'y', 'yes'], true);
}

function envQuote(string $v): string
{
    if ($v === '' || preg_match('/^[A-Za-z0-9_.,:\/\-]+$/', $v)) {
        return $v;
    }
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
}

function readEnvFile(string $path): array
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === "'" || $v[0] === '"') && $v[-1] === $v[0]) {
            $v = stripcslashes(substr($v, 1, -1));
        }
        $out[trim($k)] = $v;
    }
    return $out;
}

say();
say('=== Discord KI-Bot - Installation ===');
say();

// ------------------------------------------------------------ 1. Voraussetzungen
say('1/5  Voraussetzungen pruefen');
if (version_compare(PHP_VERSION, ROOT_MIN_PHP, '<')) {
    fail('PHP ' . ROOT_MIN_PHP . ' oder neuer wird benoetigt (aktuell ' . PHP_VERSION . ').');
}
ok('PHP ' . PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        fail("PHP-Erweiterung fehlt: $ext (in der php.ini aktivieren).");
    }
    ok("Erweiterung $ext");
}
if (!extension_loaded('zip')) {
    warn('Erweiterung zip fehlt - Composer nutzt dann ggf. git/unzip. Empfohlen: zip aktivieren.');
}

// ------------------------------------------------------------ 2. Composer + Abhaengigkeiten
say();
say('2/5  Abhaengigkeiten installieren');
$composerCmd = null;
$probe = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'composer --version 2>NUL' : 'composer --version 2>/dev/null';
exec($probe, $o, $code);
if ($code === 0) {
    $composerCmd = 'composer';
    ok('Globales Composer gefunden');
} else {
    $phar = $root . DIRECTORY_SEPARATOR . 'composer.phar';
    if (!is_file($phar)) {
        say('  Composer wird heruntergeladen ...');
        $data = @file_get_contents('https://getcomposer.org/download/latest-stable/composer.phar');
        if ($data === false || strlen($data) < 1000000) {
            fail('Composer konnte nicht heruntergeladen werden. Internetverbindung pruefen oder Composer manuell installieren.');
        }
        file_put_contents($phar, $data);
    }
    $composerCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar);
    ok('Lokales composer.phar');
}
passthru($composerCmd . ' install --no-dev --no-interaction --prefer-dist --optimize-autoloader', $code);
if ($code !== 0 || !is_file($root . '/vendor/autoload.php')) {
    fail('composer install ist fehlgeschlagen.');
}
ok('Abhaengigkeiten installiert');

// ------------------------------------------------------------ 3. Konfiguration
say();
say('3/5  Konfiguration (Enter uebernimmt den Vorschlag in [ ])');
$envPath = $root . '/.env';
$old = readEnvFile($envPath);
$ex = readEnvFile($root . '/.env.example');
$cur = fn(string $k, string $fallback = '') => $old[$k] ?? $ex[$k] ?? $fallback;

$cfg = [];
$fields = [
    ['DISCORD_TOKEN', 'Discord Bot-Token', true],
    ['GUILD_ID', 'Server-ID (Guild)', false],
    ['DB_HOST', 'Datenbank-Host', true],
    ['DB_PORT', 'Datenbank-Port', true],
    ['DB_NAME', 'Datenbank-Name', true],
    ['DB_USER', 'Datenbank-Benutzer', true],
    ['DB_PASS', 'Datenbank-Passwort', false],
    ['SERVER_NAME', 'Servername', true],
    ['ADDRESS_FORM', 'Anrede (du/sie)', true],
    ['TEAM_ROLE_IDS', 'Team-Rollen-IDs (kommagetrennt)', false],
    ['MOD_ROLE_ID', 'Rolle fuer Eskalationen (optional)', false],
    ['LOG_CHANNEL_ID', 'Log-Channel-ID (optional)', false],
    ['QUESTION_CHANNEL_IDS', 'Support-Channel-IDs (leer = alle)', false],
];
foreach ($fields as [$key, $label, $required]) {
    $cfg[$key] = ask($label, $cur($key), $required);
}
// Web-Panel-Zugang
say();
say('  Web-Panel-Zugang');
$cfg['PANEL_USER'] = ask('Panel-Benutzername', $cur('PANEL_USER', 'admin'), true);
$cfg['PANEL_PORT'] = ask('Panel-Port', $cur('PANEL_PORT', '8080'), true);
$existingHash = $old['PANEL_PASSWORD_HASH'] ?? '';
while (true) {
    $pw = ask('Panel-Passwort (mind. 8 Zeichen)' . ($existingHash !== '' ? ' - Enter = unveraendert' : ''), '', $existingHash === '');
    if ($pw === '' && $existingHash !== '') {
        $cfg['PANEL_PASSWORD_HASH'] = $existingHash;
        break;
    }
    if (strlen($pw) >= 8) {
        $cfg['PANEL_PASSWORD_HASH'] = password_hash($pw, PASSWORD_DEFAULT);
        break;
    }
    warn('Das Passwort muss mindestens 8 Zeichen lang sein.');
}
say();

// uebrige Werte unveraendert aus bestehender .env bzw. Vorlage
foreach ($ex as $k => $v) {
    $cfg[$k] ??= $old[$k] ?? $v;
}

// ------------------------------------------------------------ 4. Pruefungen
say();
say('4/5  Zugangsdaten pruefen');

$ctx = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "Authorization: Bot {$cfg['DISCORD_TOKEN']}\r\nUser-Agent: DiscordBot (install.php, 1.0)\r\n",
    'ignore_errors' => true,
    'timeout' => 15,
]]);
$resp = @file_get_contents('https://discord.com/api/v10/users/@me', false, $ctx);
$botInfo = $resp !== false ? json_decode($resp, true) : null;
$clientId = null;
if (is_array($botInfo) && isset($botInfo['id'], $botInfo['username'])) {
    $clientId = $botInfo['id'];
    ok('Discord-Token gueltig (Bot: ' . $botInfo['username'] . ')');
} else {
    warn('Discord-Token konnte nicht verifiziert werden. Bitte pruefen.');
    if (!askYesNo('Trotzdem fortfahren?', false)) {
        fail('Abgebrochen.');
    }
}

try {
    $dsnBase = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['DB_HOST'], (int) $cfg['DB_PORT']);
    $pdo = new PDO($dsnBase, $cfg['DB_USER'], $cfg['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ok('Datenbank-Verbindung hergestellt');
    $dbName = str_replace('`', '', $cfg['DB_NAME']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    ok("Datenbank '$dbName' vorhanden");
} catch (PDOException $e) {
    fail('Datenbank-Fehler: ' . $e->getMessage());
}

// .env schreiben (nach erfolgreicher Pruefung)
$lines = ['# LIVE-Datei - erzeugt von install.php. Nicht auf GitHub hochladen!'];
foreach ($cfg as $k => $v) {
    $lines[] = $k . '=' . envQuote((string) $v);
}
file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
@chmod($envPath, 0600);
ok('.env geschrieben');

// Tabellen anlegen
require $root . '/vendor/autoload.php';
foreach ($cfg as $k => $v) {
    $_ENV[$k] = (string) $v;
    putenv("$k=$v");
}
try {
    new App\Database();
    ok('Tabellen angelegt');
} catch (Throwable $e) {
    fail('Tabellen konnten nicht angelegt werden: ' . $e->getMessage());
}

// ------------------------------------------------------------ 5. Abschluss
say();
say('5/5  Fertig!');
if ($clientId !== null) {
    say();
    say('  Bot zum Server einladen (falls noch nicht geschehen):');
    say("  https://discord.com/oauth2/authorize?client_id={$clientId}&scope=bot&permissions=68608");
}
say();
say('  Wichtig: Im Discord Developer Portal (Bot -> Privileged Gateway Intents)');
say('  "Message Content Intent" und "Server Members Intent" aktivieren.');
say();

$panelPort = (int) $cfg['PANEL_PORT'];
$panelCmd = escapeshellarg(PHP_BINARY) . ' -S 0.0.0.0:' . $panelPort . ' -t ' . escapeshellarg($root . '/panel');
if (askYesNo("Web-Panel jetzt im Hintergrund starten (http://localhost:{$panelPort})?", true)) {
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        pclose(popen('start /B "" ' . $panelCmd . ' > NUL 2>&1', 'r'));
    } else {
        exec('nohup ' . $panelCmd . ' > /dev/null 2>&1 &');
    }
    ok("Web-Panel laeuft auf http://localhost:{$panelPort}");
} else {
    say("  Panel spaeter starten mit: php -S 0.0.0.0:{$panelPort} -t panel");
}
say();

if (askYesNo('Bot jetzt starten?', true)) {
    say('  Bot laeuft - mit Strg+C beenden.');
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bot.php'));
} else {
    say('  Start spaeter mit: php bot.php');
}
