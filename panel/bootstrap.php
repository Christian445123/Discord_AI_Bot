<?php
declare(strict_types=1);

$root = dirname(__DIR__);

// Fehler nicht anzeigen, aber protokollieren - bei leeren Seiten hier nachsehen: panel-error.log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/panel-error.log');

if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/.env')) {
    http_response_code(500);
    exit('Bot ist noch nicht eingerichtet. Bitte zuerst "php install.php" ausfuehren.');
}
require $root . '/vendor/autoload.php';
App\Env::load($root);

use App\Database;
use App\Env;

session_name('botpanel');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $db = null;
    return ($db ??= new Database())->pdo();
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungueltiges Formular-Token. Seite neu laden.');
    }
}

function logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function require_login(): void
{
    if (!logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function env_quote(string $v): string
{
    if ($v === '' || preg_match('/^[A-Za-z0-9_.,:\/\-]+$/', $v)) {
        return $v;
    }
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
}

/** Aktualisiert einzelne Schluessel in der .env und laesst alles andere unveraendert. */
function env_update(array $values): void
{
    $path = dirname(__DIR__) . '/.env';
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $seen = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $values)) {
            $lines[$i] = $m[1] . '=' . env_quote((string) $values[$m[1]]);
            $seen[$m[1]] = true;
        }
    }
    foreach ($values as $k => $v) {
        if (!isset($seen[$k])) {
            $lines[] = $k . '=' . env_quote((string) $v);
        }
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
}

function bot_online(PDO $pdo): bool
{
    $hb = $pdo->query("SELECT updated_at FROM bot_status WHERE k = 'heartbeat'")->fetchColumn();
    return $hb && (time() - strtotime((string) $hb)) < 30;
}

/** PHP-CLI-Programm finden (unter FPM/Apache zeigt PHP_BINARY nicht auf "php"). */
function php_cli(): string
{
    $env = Env::get('PHP_CLI');
    if ($env !== '') {
        return $env;
    }
    $base = strtolower(basename(PHP_BINARY));
    if (preg_match('/^php\d*(\.\d+)?(\.exe)?$/', $base)) {
        return PHP_BINARY;
    }
    foreach ([PHP_BINDIR . '/php', PHP_BINDIR . '/php.exe', '/usr/bin/php', '/usr/local/bin/php'] as $c) {
        if (is_file($c) && is_executable($c)) {
            return $c;
        }
    }
    return 'php';
}

function fn_enabled(string $name): bool
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return function_exists($name) && !in_array($name, $disabled, true);
}

/** Startet ein PHP-Skript des Projekts losgeloest im Hintergrund. Liefert eine Fehlermeldung oder null. */
function spawn_detached(string $script, array $args, string $log): ?string
{
    $root = dirname(__DIR__);
    $cmd = implode(' ', array_map('escapeshellarg', array_merge([php_cli(), $root . DIRECTORY_SEPARATOR . $script], $args)));

    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        if (!fn_enabled('popen')) {
            return 'popen ist in der php.ini gesperrt (disable_functions)';
        }
        $h = @popen('start /B "" ' . $cmd . ' > ' . escapeshellarg($log) . ' 2>&1', 'r');
        if ($h === false) {
            return 'Prozess konnte nicht gestartet werden';
        }
        pclose($h);
        return null;
    }

    $full = 'cd ' . escapeshellarg($root) . ' && nohup ' . $cmd . ' > ' . escapeshellarg($log) . ' 2>&1 &';
    foreach (['exec', 'shell_exec', 'system', 'passthru'] as $fn) {
        if (fn_enabled($fn)) {
            $fn === 'exec' ? exec($full) : $fn($full);
            return null;
        }
    }
    if (fn_enabled('proc_open')) {
        $p = @proc_open(['sh', '-c', $full], [], $pipes);
        if (is_resource($p)) {
            return null;
        }
    }
    return 'Programme starten ist in der php.ini gesperrt (disable_functions: exec, shell_exec, system, passthru, proc_open). '
        . 'Dort freigeben oder den Bot per pm2 laufen lassen.';
}

/**
 * Legt einen Auftrag an. Laeuft der Bot, fuehrt er ihn aus; sonst startet das Panel ihn selbst.
 * Liefert die Meldung fuer den Benutzer.
 */
function dispatch_job(PDO $pdo, string $type, string $label): string
{
    $same = str_starts_with($type, 'crawl') ? ['crawl', 'crawl_full'] : [$type];
    $in = implode(',', array_fill(0, count($same), '?'));
    $open = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE type IN ($in) AND status IN ('pending','running')");
    $open->execute($same);
    if ((int) $open->fetchColumn() > 0) {
        return "„{$label}“ läuft bzw. wartet bereits.";
    }
    $pdo->prepare("INSERT INTO jobs (type, status, created_at) VALUES (?, 'pending', NOW())")->execute([$type]);
    $id = (int) $pdo->lastInsertId();

    if (bot_online($pdo) || !isset(App\Jobs::SCRIPTS[$type])) {
        return "„{$label}“ angefordert. Der Bot startet es innerhalb weniger Sekunden.";
    }
    $err = spawn_detached('run_job.php', [(string) $id], dirname(__DIR__) . '/jobrunner.log');
    if ($err !== null) {
        $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ?")->execute([mb_substr($err, 0, 250), $id]);
        return "„{$label}“ konnte nicht gestartet werden: {$err}";
    }
    return "„{$label}“ gestartet. (Der Bot ist offline - das Panel führt den Auftrag direkt aus.)";
}

/** Auftraege, die der Bot nach 20 s nicht uebernommen hat, startet das Panel selbst. */
function rescue_stale_jobs(PDO $pdo): void
{
    try {
        $rows = $pdo->query("SELECT id, type FROM jobs WHERE status = 'pending' AND created_at < NOW() - INTERVAL 20 SECOND")->fetchAll();
        foreach ($rows as $r) {
            if (!isset(App\Jobs::SCRIPTS[$r['type']])) {
                continue;
            }
            $err = spawn_detached('run_job.php', [(string) $r['id']], dirname(__DIR__) . '/jobrunner.log');
            if ($err !== null) {
                $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ? AND status = 'pending'")
                    ->execute([mb_substr($err, 0, 250), $r['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('rescue_stale_jobs: ' . $e->getMessage());
    }
}

function page_header(string $title, string $active = ''): void
{
    $nav = [
        'dashboard.php' => 'Dashboard',
        'control.php' => 'Steuerung',
        'knowledge.php' => 'Wissen',
        'server_info.php' => 'Server-Infos',
        'channels.php' => 'Channels',
        'interventions.php' => 'Eingriffe',
        'settings.php' => 'Einstellungen',
        'updates.php' => 'Updates',
    ];
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' - Bot-Panel</title><style>'
        . ':root{--bg:#0f1115;--card:#181b22;--line:#2a2f3a;--text:#e6e8ee;--mute:#8b93a5;--acc:#5865f2;--bad:#ed4245;--ok:#3ba55d}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,Segoe UI,sans-serif}'
        . 'a{color:var(--acc);text-decoration:none}header{background:var(--card);border-bottom:1px solid var(--line);padding:0 16px;display:flex;gap:4px;align-items:center;flex-wrap:wrap}'
        . 'header b{margin-right:16px;padding:14px 0}header a{color:var(--mute);padding:14px 12px}header a.on,header a:hover{color:var(--text)}header .sp{flex:1}'
        . 'main{max-width:1100px;margin:0 auto;padding:20px 16px}h1{font-size:22px;margin:0 0 16px}'
        . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px}'
        . '.stat{font-size:28px;font-weight:700}.mute{color:var(--mute);font-size:13px}'
        . 'table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:8px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--mute);font-weight:600;font-size:13px}'
        . 'input,textarea,select{width:100%;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:6px;padding:8px;font:inherit}'
        . 'textarea{min-height:90px}label{display:block;margin:10px 0 4px;color:var(--mute);font-size:13px}'
        . 'button,.btn{background:var(--acc);color:#fff;border:0;border-radius:6px;padding:8px 14px;font:inherit;cursor:pointer;display:inline-block}'
        . 'button.del{background:var(--bad)}button.sec{background:var(--line)}.flash{background:var(--ok);color:#fff;padding:10px 14px;border-radius:6px;margin-bottom:16px}'
        . '.err{background:var(--bad)}.row{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.row>*{flex:1}.row>button{flex:0}'
        . 'form.inline{display:inline}.pager{display:flex;gap:8px;margin-top:12px}'
        . '</style></head><body>';
    if (logged_in()) {
        echo '<header><b>Bot-Panel</b>';
        foreach ($nav as $file => $label) {
            echo '<a href="' . $file . '"' . ($file === $active ? ' class="on"' : '') . '>' . $label . '</a>';
        }
        echo '<span class="sp"></span><a href="logout.php">Abmelden (' . h($_SESSION['user']) . ')</a></header>';
    }
    echo '<main>';
    if (logged_in()) {
        rescue_stale_jobs(db());
        try {
            $tm = db()->query("SELECT v FROM settings WHERE k = 'TEST_MODE'")->fetchColumn();
            if (strtolower((string) $tm) === 'true') {
                echo '<div class="flash" style="background:#f0b232;color:#1b1b1b">🧪 <b>Testmodus aktiv</b> – Teamler werden nicht '
                    . 'ignoriert, es wird nichts gelernt. <a href="control.php" style="color:#1b1b1b;text-decoration:underline">Zur Steuerung</a></div>';
            }
        } catch (Throwable $e) {
            // Banner ist optional
        }
    }
    $f = flash();
    if ($f !== null) {
        echo '<div class="flash">' . h($f) . '</div>';
    }
}

function page_footer(): void
{
    echo '</main></body></html>';
}
