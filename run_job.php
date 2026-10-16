<?php
declare(strict_types=1);

/**
 * Fuehrt einen Panel-Auftrag aus, ohne dass der Bot laufen muss:  php run_job.php <job-id>
 * Wird vom Web-Panel selbst gestartet, wenn der Bot den Auftrag nicht uebernimmt (offline / alter Code).
 */

$root = __DIR__;
chdir($root);

if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/.env')) {
    fwrite(STDERR, "Nicht eingerichtet (vendor/.env fehlt).\n");
    exit(1);
}
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

use App\Database;
use App\Jobs;

$id = (int) ($argv[1] ?? 0);
$db = new Database();
$pdo = $db->pdo();

$st = $pdo->prepare('SELECT id, type FROM jobs WHERE id = ?');
$st->execute([$id]);
$job = $st->fetch();
if (!$job || !isset(Jobs::SCRIPTS[$job['type']])) {
    $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = 'Unbekannter Auftrag' WHERE id = ? AND status IN ('pending','running')")
        ->execute([$id]);
    exit(1);
}

// Auftrag atomar uebernehmen - der Bot koennte ihn parallel ebenfalls aufgreifen.
$claim = $pdo->prepare("UPDATE jobs SET status = 'running', started_at = NOW(), note = 'vom Panel gestartet' WHERE id = ? AND status = 'pending'");
$claim->execute([$id]);
if ($claim->rowCount() === 0) {
    echo "Auftrag #{$id} wurde bereits uebernommen.\n";
    exit(0);
}

[$script, $args, $logName] = Jobs::SCRIPTS[$job['type']];
$log = $root . DIRECTORY_SEPARATOR . $logName;
$null = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'NUL' : '/dev/null';

$proc = proc_open(
    array_merge([PHP_BINARY, $root . DIRECTORY_SEPARATOR . $script], $args),
    [0 => ['file', $null, 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
    $pipes,
    $root
);
if (!is_resource($proc)) {
    $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ?")
        ->execute(['Prozess konnte nicht gestartet werden (proc_open gesperrt?)', $id]);
    exit(1);
}
$code = proc_close($proc);

[$ok, $note] = Jobs::outcome($job['type'], $code);
if ($job['type'] === 'update_apply' && $code === 0) {
    $note = 'Update installiert - Bot bitte neu starten (pm2 restart discord-ai-bot)';
}
$pdo->prepare('UPDATE jobs SET status = ?, finished_at = NOW(), note = ? WHERE id = ?')
    ->execute([$ok ? 'done' : 'failed', 'vom Panel: ' . $note, $id]);
exit($ok ? 0 : 1);
