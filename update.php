<?php
declare(strict_types=1);

/**
 * GitHub-Update:
 *   php update.php check   - holt den Stand von GitHub (git fetch) und speichert ihn fuer das Panel
 *   php update.php apply   - uebernimmt Aenderungen (git pull --ff-only) und aktualisiert ggf. Composer-Pakete
 *
 * Exit-Codes (apply): 0 = aktualisiert, 10 = bereits aktuell, 2 = lokale Aenderungen, 1 = Fehler
 * Die .env ist in der .gitignore und wird nie angefasst.
 */

$root = __DIR__;
chdir($root);
$mode = $argv[1] ?? 'check';

function out(string $s): void
{
    echo '[' . date('H:i:s') . '] ' . $s . PHP_EOL;
}

/** @return array{0:int,1:string} */
function git(string $args): array
{
    $cmd = 'git -c safe.directory=' . escapeshellarg(__DIR__) . ' ' . $args . ' 2>&1';
    exec($cmd, $lines, $code);
    return [$code, trim(implode("\n", $lines))];
}

function fail(string $m, int $code = 1): never
{
    out('FEHLER: ' . $m);
    exit($code);
}

if (!is_dir($root . '/.git')) {
    fail('Kein Git-Repository (.git fehlt). Das Projekt muss per "git clone" installiert worden sein.');
}
[$c, $v] = git('--version');
if ($c !== 0) {
    fail('git ist nicht installiert oder nicht erreichbar: ' . $v);
}
out($v);

[$c, $branch] = git('rev-parse --abbrev-ref HEAD');
[$c, $upstream] = git('rev-parse --abbrev-ref --symbolic-full-name @{u}');
if ($c !== 0) {
    fail('Kein Upstream-Branch konfiguriert (git branch --set-upstream-to=origin/main): ' . $upstream);
}
out("Branch: {$branch} -> {$upstream}");

/** Sammelt den aktuellen Stand und schreibt ihn fuer das Panel in die Datenbank. */
function collect(string $branch, string $upstream): array
{
    [, $head] = git('rev-parse HEAD');
    [, $headInfo] = git('log -1 --format=%h%x09%ad%x09%s --date=format:%d.%m.%Y_%H:%M');
    [, $remote] = git('rev-parse @{u}');
    [, $behind] = git('rev-list --count HEAD..@{u}');
    [, $log] = git('log --format=%h%x09%an%x09%ad%x09%s --date=format:%d.%m.%Y_%H:%M -n 20 HEAD..@{u}');
    [, $dirty] = git('status --porcelain --untracked-files=no');
    $commits = [];
    foreach (array_filter(explode("\n", $log)) as $line) {
        $p = explode("\t", $line, 4);
        $commits[] = ['hash' => $p[0] ?? '', 'author' => $p[1] ?? '', 'date' => str_replace('_', ' ', $p[2] ?? ''), 'subject' => $p[3] ?? ''];
    }
    $hi = explode("\t", $headInfo, 3);
    return [
        'branch' => $branch,
        'upstream' => $upstream,
        'head' => $head,
        'current' => ['hash' => $hi[0] ?? '', 'date' => str_replace('_', ' ', $hi[1] ?? ''), 'subject' => $hi[2] ?? ''],
        'remote' => $remote,
        'behind' => (int) $behind,
        'dirty' => $dirty === '' ? [] : explode("\n", $dirty),
        'checked_at' => date('Y-m-d H:i:s'),
        'commits' => $commits,
    ];
}

function store(array $info): void
{
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
        $db = new App\Database();
        $db->pdo()->prepare(
            'INSERT INTO bot_status (k, v, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()'
        )->execute(['update_info', json_encode($info, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        out('Hinweis: Stand konnte nicht in der Datenbank gespeichert werden: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------ check
out('Hole Stand von GitHub (git fetch) ...');
[$c, $o] = git('fetch --prune');
if ($c !== 0) {
    fail('git fetch fehlgeschlagen: ' . $o);
}
$info = collect($branch, $upstream);
store($info);

if ($mode === 'check') {
    out($info['behind'] > 0 ? "{$info['behind']} neue Aenderung(en) verfuegbar." : 'Bereits aktuell.');
    foreach ($info['commits'] as $cm) {
        out("  {$cm['hash']} {$cm['subject']} ({$cm['author']}, {$cm['date']})");
    }
    exit(0);
}

// ------------------------------------------------------------ apply
if ($info['behind'] === 0) {
    out('Bereits aktuell - nichts zu tun.');
    exit(10);
}
if ($info['dirty']) {
    out('Lokale Aenderungen an versionierten Dateien:');
    foreach ($info['dirty'] as $d) {
        out('  ' . $d);
    }
    fail('Update abgebrochen, um lokale Aenderungen nicht zu ueberschreiben. Bitte zuerst committen/zuruecksetzen.', 2);
}

$old = $info['head'];
out("Uebernehme {$info['behind']} Aenderung(en) (git pull --ff-only) ...");
[$c, $o] = git('pull --ff-only');
out($o);
if ($c !== 0) {
    fail('git pull fehlgeschlagen (Exit-Code ' . $c . ').');
}

[, $new] = git('rev-parse HEAD');
[, $changed] = git('diff --name-only ' . escapeshellarg($old) . ' ' . escapeshellarg($new));
$files = array_filter(explode("\n", $changed));
out(count($files) . ' Datei(en) geaendert.');

if (array_intersect($files, ['composer.json', 'composer.lock'])) {
    out('composer.json/lock geaendert - aktualisiere Abhaengigkeiten ...');
    exec(DIRECTORY_SEPARATOR === '\\' ? 'composer --version 2>NUL' : 'composer --version 2>/dev/null', $o2, $cc);
    $composer = $cc === 0 ? 'composer' : escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/composer.phar');
    putenv('COMPOSER_ALLOW_SUPERUSER=1');
    passthru($composer . ' install --no-dev --no-interaction --prefer-dist --optimize-autoloader 2>&1', $cc);
    if ($cc !== 0) {
        fail('composer install fehlgeschlagen (Exit-Code ' . $cc . ').');
    }
}

store(collect($branch, $upstream));
out('Update erfolgreich installiert. Der Bot startet neu, damit der neue Code aktiv wird.');
exit(0);
