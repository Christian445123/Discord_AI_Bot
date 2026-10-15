<?php
/**
 * Ein-Skript-Installation:  php install.php
 *
 * Alle Schritte und Fehler werden live auf der Konsole ausgegeben und zusaetzlich
 * in install.log geschrieben (Zugangsdaten werden dort maskiert).
 *
 * Optionen:  -v / --verbose   zeigt zusaetzliche Diagnose-Details
 *
 * Hinweis: Diese Datei nutzt bewusst nur aeltere PHP-Syntax, damit auch auf zu alten
 * PHP-Versionen eine verstaendliche Meldung statt eines Parse-Errors erscheint.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

if (PHP_SAPI !== 'cli') {
    exit("Bitte per Konsole ausfuehren: php install.php\n");
}
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "[FEHLER] PHP 8.1 oder neuer wird benoetigt. Aktuell: " . PHP_VERSION . " (" . PHP_BINARY . ")\n");
    fwrite(STDERR, "         Tipp: 'php -v' und 'ls /usr/bin/php*' pruefen - evtl. gibt es z.B. 'php8.2'.\n");
    exit(1);
}

$GLOBALS['root'] = __DIR__;
$GLOBALS['logFile'] = __DIR__ . DIRECTORY_SEPARATOR . 'install.log';
$GLOBALS['verbose'] = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
$GLOBALS['secrets'] = [];
chdir(__DIR__);

// ------------------------------------------------------------ Logging
function logline($level, $msg)
{
    foreach ($GLOBALS['secrets'] as $s) {
        if ($s !== '' && strlen($s) >= 4) {
            $msg = str_replace($s, '***', $msg);
        }
    }
    @file_put_contents($GLOBALS['logFile'], '[' . date('Y-m-d H:i:s') . '] ' . $level . ' ' . $msg . PHP_EOL, FILE_APPEND);
}
function say($s = '') { echo $s . PHP_EOL; logline('INFO ', $s); }
/** Kuendigt eine Aktion an, bevor sie startet - so ist immer sichtbar, was gerade passiert. */
function step($s)     { echo "  -> $s ..." . PHP_EOL; logline('STEP ', $s); }
function ok($s)       { echo "  [OK]     $s" . PHP_EOL; logline('OK   ', $s); }
function warn($s)     { echo "  [WARN]   $s" . PHP_EOL; logline('WARN ', $s); }
function dbg($s)      { logline('DEBUG', $s); if ($GLOBALS['verbose']) { echo "  [debug]  $s" . PHP_EOL; } }
function fail($s)
{
    fwrite(STDERR, "  [FEHLER] $s" . PHP_EOL);
    logline('ERROR', $s);
    fwrite(STDERR, "  Details siehe: " . $GLOBALS['logFile'] . PHP_EOL);
    exit(1);
}

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) {
        return false;
    }
    $m = "PHP-Meldung ($no): $str in $file:$line";
    fwrite(STDERR, "  [PHP]    $m" . PHP_EOL);
    logline('PHPERR', $m);
    return true;
});
set_exception_handler(function ($e) {
    $m = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString();
    fwrite(STDERR, "  [FEHLER] Unbehandelte Ausnahme:" . PHP_EOL . $m . PHP_EOL);
    logline('FATAL', $m);
    fwrite(STDERR, "  Details siehe: " . $GLOBALS['logFile'] . PHP_EOL);
    exit(1);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $m = "Fataler Fehler: {$e['message']} in {$e['file']}:{$e['line']}";
        fwrite(STDERR, "  [FEHLER] $m" . PHP_EOL);
        logline('FATAL', $m);
    }
});

// ------------------------------------------------------------ Hilfsfunktionen
function ask($label, $default = '', $required = false)
{
    while (true) {
        $suffix = $default !== '' ? " [$default]" : '';
        echo "  $label$suffix: ";
        $line = fgets(STDIN);
        if ($line === false) {
            fail('Keine Eingabe moeglich (STDIN geschlossen / kein interaktives Terminal).');
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

function askYesNo($label, $default = true)
{
    $a = strtolower(ask($label . ($default ? ' (J/n)' : ' (j/N)')));
    if ($a === '') {
        return $default;
    }
    return in_array($a, ['j', 'ja', 'y', 'yes'], true);
}

function envQuote($v)
{
    if ($v === '' || preg_match('/^[A-Za-z0-9_.,:\/\-]+$/', $v)) {
        return $v;
    }
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
}

function readEnvFile($path)
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === "'" || $v[0] === '"') && substr($v, -1) === $v[0]) {
            $v = stripcslashes(substr($v, 1, -1));
        }
        $out[trim($k)] = $v;
    }
    return $out;
}

function isWindows()
{
    return stripos(PHP_OS_FAMILY, 'Windows') === 0;
}

function fnAvailable($name)
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array($name, $disabled, true);
}

/** Fuehrt einen Befehl aus, gibt die Ausgabe live aus und schreibt sie ins Log. Liefert den Exit-Code. */
function run($cmd, array $env = [])
{
    logline('CMD  ', $cmd);
    if (fnAvailable('proc_open')) {
        $envVars = $env ? array_merge(getenv(), $env) : null;
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $GLOBALS['root'], $envVars);
        if (!is_resource($p)) {
            warn('Befehl konnte nicht gestartet werden.');
            return 255;
        }
        // Rohdaten sofort durchreichen (auch Fortschritt ohne Zeilenumbruch), damit nie "nichts" zu sehen ist.
        $buf = '';
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk === false || $chunk === '') {
                usleep(50000);
                continue;
            }
            echo $chunk;
            $buf .= $chunk;
            while (($pos = strpos($buf, "\n")) !== false) {
                logline('OUT  ', rtrim(substr($buf, 0, $pos)));
                $buf = substr($buf, $pos + 1);
            }
        }
        if (trim($buf) !== '') {
            logline('OUT  ', trim($buf));
        }
        fclose($pipes[1]);
        return proc_close($p);
    }
    if (fnAvailable('passthru')) {
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }
        passthru($cmd . ' 2>&1', $code);
        return $code;
    }
    fail('Weder proc_open noch passthru sind verfuegbar (disable_functions in der php.ini). Composer kann nicht ausgefuehrt werden.');
}

/** HTTP-GET mit curl bzw. file_get_contents. Liefert [status, body, fehler]. */
function httpGet($url, array $headers = [], $timeout = 30)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'DiscordBot (install.php, 1.0)',
        ]);
        $body = curl_exec($ch);
        $err = $body === false ? curl_error($ch) : '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, $body === false ? '' : $body, $err];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", array_merge($headers, ['User-Agent: DiscordBot (install.php, 1.0)'])),
            'ignore_errors' => true,
            'timeout' => $timeout,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $err = $body === false ? (error_get_last()['message'] ?? 'unbekannter Fehler') : '';
        return [$status, $body === false ? '' : $body, $err];
    }
    return [0, '', 'Weder die curl-Erweiterung noch allow_url_fopen sind verfuegbar.'];
}

function dbHint($e)
{
    $m = $e->getMessage();
    $c = (string) $e->getCode();
    if (strpos($m, '1045') !== false) {
        return 'Benutzername oder Passwort der Datenbank ist falsch.';
    }
    if (strpos($m, '2002') !== false || strpos($m, 'Connection refused') !== false || strpos($m, 'php_network_getaddresses') !== false) {
        return 'Datenbank-Host/-Port nicht erreichbar. Laeuft MySQL/MariaDB? Host und Port korrekt? Firewall?';
    }
    if (strpos($m, '1049') !== false) {
        return 'Die Datenbank existiert nicht. Bitte manuell anlegen oder dem Benutzer CREATE-Rechte geben.';
    }
    if (strpos($m, '1044') !== false || strpos($m, '1142') !== false) {
        return 'Dem Datenbank-Benutzer fehlen Rechte (z.B. CREATE). Datenbank manuell anlegen und Rechte vergeben.';
    }
    if (strpos($m, 'could not find driver') !== false) {
        return 'PHP-Erweiterung pdo_mysql fehlt (apt install php-mysql).';
    }
    return 'Siehe Fehlermeldung oben (SQLSTATE ' . $c . ').';
}

// ------------------------------------------------------------ Start
@file_put_contents($GLOBALS['logFile'], '');
say();
say('=== Discord KI-Bot - Installation ===');
say('Log-Datei: ' . $GLOBALS['logFile'] . ($GLOBALS['verbose'] ? ' (verbose)' : ' (mit -v mehr Details)'));
say();

// ------------------------------------------------------------ 1. Voraussetzungen
say('1/5  Voraussetzungen pruefen');
ok('PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ')');
dbg('OS: ' . php_uname());
dbg('php.ini: ' . (php_ini_loaded_file() ?: 'keine'));
dbg('Arbeitsverzeichnis: ' . getcwd());
dbg('memory_limit: ' . ini_get('memory_limit') . ', allow_url_fopen: ' . ini_get('allow_url_fopen'));
dbg('disable_functions: ' . (ini_get('disable_functions') ?: '(leer)'));
dbg('Geladene Erweiterungen: ' . implode(', ', get_loaded_extensions()));

foreach (['pdo_mysql', 'mbstring', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        fail("PHP-Erweiterung fehlt: $ext (in der php.ini aktivieren bzw. 'apt install php-" . str_replace('pdo_', '', $ext) . "').");
    }
    ok("Erweiterung $ext");
}
if (!extension_loaded('zip')) {
    warn('Erweiterung zip fehlt - Composer nutzt dann ggf. git/unzip. Empfohlen: apt install php-zip unzip');
}
if (!extension_loaded('curl') && !ini_get('allow_url_fopen')) {
    warn('Weder curl noch allow_url_fopen verfuegbar - Downloads werden fehlschlagen.');
}
if (!is_writable(__DIR__)) {
    fail('Projektordner ist nicht beschreibbar: ' . __DIR__ . ' (Rechte pruefen, z.B. chown/chmod).');
}
ok('Projektordner beschreibbar');

// ------------------------------------------------------------ 2. Composer + Abhaengigkeiten
say();
say('2/5  Abhaengigkeiten installieren');
$composerCmd = null;
$out = [];
$code = 1;
step('Suche installiertes Composer');
if (fnAvailable('exec')) {
    exec(isWindows() ? 'composer --version 2>NUL' : 'composer --version 2>/dev/null', $out, $code);
}
if ($code === 0) {
    $composerCmd = 'composer';
    ok('Globales Composer gefunden: ' . trim(implode(' ', $out)));
} else {
    $phar = __DIR__ . DIRECTORY_SEPARATOR . 'composer.phar';
    if (!is_file($phar)) {
        step('Composer nicht gefunden - lade composer.phar von getcomposer.org herunter (ca. 3 MB)');
        list($status, $body, $err) = httpGet('https://getcomposer.org/download/latest-stable/composer.phar', [], 120);
        dbg("Download: HTTP $status, " . strlen($body) . ' Bytes' . ($err ? ", Fehler: $err" : ''));
        if ($status !== 200 || strlen($body) < 1000000) {
            fail('Composer konnte nicht heruntergeladen werden (HTTP ' . $status . ($err ? ', ' . $err : '') . '). '
                . 'Internetverbindung/Firewall pruefen oder Composer manuell installieren.');
        }
        file_put_contents($phar, $body);
        ok('composer.phar heruntergeladen (' . round(strlen($body) / 1048576, 1) . ' MB)');
    }
    $composerCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar);
    ok('Lokales composer.phar wird verwendet');
}

step('Installiere Bot-Pakete mit Composer (DiscordPHP, phpdotenv) - das kann 1-2 Minuten dauern');
$code = run(
    $composerCmd . ' install --no-dev --no-interaction --prefer-dist --optimize-autoloader -v',
    ['COMPOSER_ALLOW_SUPERUSER' => '1', 'COMPOSER_HOME' => __DIR__ . '/.composer']
);
if ($code !== 0) {
    fail("composer install ist fehlgeschlagen (Exit-Code $code). Ausgabe siehe oben.");
}
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    fail('composer install lief durch, aber vendor/autoload.php fehlt.');
}
ok('Abhaengigkeiten installiert');

// ------------------------------------------------------------ 3. Konfiguration
say();
say('3/5  Konfiguration (Enter uebernimmt den Vorschlag in [ ])');
$envPath = __DIR__ . '/.env';
$old = readEnvFile($envPath);
$ex = readEnvFile(__DIR__ . '/.env.example');
$cur = function ($k, $fallback = '') use ($old, $ex) {
    if (isset($old[$k])) {
        return $old[$k];
    }
    return isset($ex[$k]) ? $ex[$k] : $fallback;
};

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
foreach ($fields as $f) {
    $cfg[$f[0]] = ask($f[1], $cur($f[0]), $f[2]);
}
$GLOBALS['secrets'] = [$cfg['DISCORD_TOKEN'], $cfg['DB_PASS']];

say();
say('  Web-Panel-Zugang');
$cfg['PANEL_USER'] = ask('Panel-Benutzername', $cur('PANEL_USER', 'admin'), true);
$cfg['PANEL_PORT'] = ask('Panel-Port', $cur('PANEL_PORT', '8080'), true);
$existingHash = isset($old['PANEL_PASSWORD_HASH']) ? $old['PANEL_PASSWORD_HASH'] : '';
while (true) {
    $pw = ask('Panel-Passwort (mind. 8 Zeichen)' . ($existingHash !== '' ? ' - Enter = unveraendert' : ''), '', $existingHash === '');
    if ($pw === '' && $existingHash !== '') {
        $cfg['PANEL_PASSWORD_HASH'] = $existingHash;
        break;
    }
    if (strlen($pw) >= 8) {
        $cfg['PANEL_PASSWORD_HASH'] = password_hash($pw, PASSWORD_DEFAULT);
        $GLOBALS['secrets'][] = $pw;
        break;
    }
    warn('Das Passwort muss mindestens 8 Zeichen lang sein.');
}
say();

// uebrige Werte unveraendert aus bestehender .env bzw. Vorlage
foreach ($ex as $k => $v) {
    if (!isset($cfg[$k])) {
        $cfg[$k] = isset($old[$k]) ? $old[$k] : $v;
    }
}

// ------------------------------------------------------------ 4. Pruefungen
say('4/5  Zugangsdaten pruefen');

step('Pruefe Discord-Token bei discord.com');
list($status, $body, $err) = httpGet('https://discord.com/api/v10/users/@me', ['Authorization: Bot ' . $cfg['DISCORD_TOKEN']], 15);
dbg("Discord-API: HTTP $status" . ($err ? ", Fehler: $err" : '') . ', Antwort: ' . substr($body, 0, 200));
$botInfo = json_decode($body, true);
$clientId = null;
if ($status === 200 && is_array($botInfo) && isset($botInfo['id'], $botInfo['username'])) {
    $clientId = $botInfo['id'];
    ok('Discord-Token gueltig (Bot: ' . $botInfo['username'] . ')');
} else {
    $why = $status === 401 ? 'Token ungueltig (401).' : ($status === 0 ? 'Keine Verbindung: ' . $err : "HTTP $status");
    warn('Discord-Token konnte nicht verifiziert werden: ' . $why);
    if (!askYesNo('Trotzdem fortfahren?', false)) {
        fail('Abgebrochen.');
    }
}

$pdo = null;
$dbName = str_replace('`', '', $cfg['DB_NAME']);
step('Verbinde mit Datenbank-Server ' . $cfg['DB_HOST'] . ':' . $cfg['DB_PORT']);
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['DB_HOST'], (int) $cfg['DB_PORT']),
        $cfg['DB_USER'],
        $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
    );
    ok('Datenbank-Server erreichbar (' . $cfg['DB_HOST'] . ':' . $cfg['DB_PORT'] . ')');
} catch (PDOException $e) {
    logline('ERROR', 'DB-Verbindung: ' . $e->getMessage());
    fwrite(STDERR, '  [FEHLER] Datenbank: ' . $e->getMessage() . PHP_EOL);
    fail('Datenbank-Verbindung fehlgeschlagen. Hinweis: ' . dbHint($e));
}
step("Lege Datenbank '$dbName' an, falls sie fehlt");
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    ok("Datenbank '$dbName' vorhanden");
} catch (PDOException $e) {
    warn('CREATE DATABASE nicht moeglich: ' . $e->getMessage() . ' - versuche, die Datenbank direkt zu verwenden.');
}

step('Schreibe Zugangsdaten in die .env');
$lines = ['# LIVE-Datei - erzeugt von install.php. Nicht auf GitHub hochladen!'];
foreach ($cfg as $k => $v) {
    $lines[] = $k . '=' . envQuote((string) $v);
}
if (file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
    fail('.env konnte nicht geschrieben werden: ' . $envPath);
}
@chmod($envPath, 0600);
ok('.env geschrieben (' . $envPath . ')');

step('Lege Tabellen an (knowledge, interventions)');
require __DIR__ . '/vendor/autoload.php';
foreach ($cfg as $k => $v) {
    $_ENV[$k] = (string) $v;
    putenv("$k=$v");
}
try {
    new App\Database();
    ok('Tabellen angelegt (knowledge, interventions)');
} catch (Throwable $e) {
    logline('ERROR', 'Tabellen: ' . $e->getMessage());
    fwrite(STDERR, '  [FEHLER] ' . $e->getMessage() . PHP_EOL);
    fail('Tabellen konnten nicht angelegt werden. Hinweis: ' . ($e instanceof PDOException ? dbHint($e) : 'siehe Meldung oben.'));
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
$panelCmd = escapeshellarg(PHP_BINARY) . ' -S 0.0.0.0:' . $panelPort . ' -t ' . escapeshellarg(__DIR__ . '/panel');
if (askYesNo("Web-Panel jetzt im Hintergrund starten (http://localhost:{$panelPort})?", true)) {
    if (isWindows()) {
        pclose(popen('start /B "" ' . $panelCmd . ' > NUL 2>&1', 'r'));
    } else {
        exec('nohup ' . $panelCmd . ' > ' . escapeshellarg(__DIR__ . '/panel.log') . ' 2>&1 &');
    }
    ok("Web-Panel gestartet auf http://localhost:{$panelPort}" . (isWindows() ? '' : ' (Log: panel.log)'));
} else {
    say("  Panel spaeter starten mit: php -S 0.0.0.0:{$panelPort} -t panel");
}
say();

if ($cfg['TEAM_ROLE_IDS'] !== '') {
    say('  Der Bot kann den Server durchsuchen und daraus Wissen speichern.');
    say('  Channels, die nur fuer das Team sichtbar sind, werden NICHT gelesen.');
    if (askYesNo('Server jetzt durchsuchen (php crawl.php)?', true)) {
        step('Durchsuche den Discord-Server (kann je nach Verlauf einige Minuten dauern)');
        $code = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/crawl.php'));
        if ($code !== 0) {
            warn("Durchsuchung mit Exit-Code $code beendet - Ausgabe siehe oben.");
        } else {
            ok('Durchsuchung abgeschlossen');
        }
        say();
    }
} else {
    warn('TEAM_ROLE_IDS ist leer - Server-Durchsuchung uebersprungen (ohne Team-Rollen kann nicht sicher gefiltert werden).');
}

if (askYesNo('Bot jetzt starten?', true)) {
    step('Starte Bot (php bot.php) - Ausgabe live unten, mit Strg+C beenden');
    $code = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bot.php'));
    say("  Bot beendet (Exit-Code $code).");
} else {
    say('  Start spaeter mit: php bot.php');
}
