<?php
declare(strict_types=1);

namespace App;

use React\EventLoop\LoopInterface;

/**
 * Wird im dauerhaft laufenden Bot (pm2) betrieben:
 *  - schreibt regelmaessig einen Heartbeat (Panel zeigt "Bot online"),
 *  - fuehrt vom Web-Panel angeforderte Auftraege aus (Server-Durchsuchung, GitHub-Update).
 */
final class Jobs
{
    private const TICK_SECONDS = 5;

    /** type => [script, args, logfile] */
    private const SCRIPTS = [
        'crawl' => ['crawl.php', [], 'crawl.log'],
        'update_check' => ['update.php', ['check'], 'update.log'],
        'update_apply' => ['update.php', ['apply'], 'update.log'],
    ];

    /** @var resource|null */
    private $proc = null;
    private ?int $runningId = null;
    private string $runningType = '';

    private int $ticks = 0;
    private int $startedAt;

    /**
     * @param array<string,callable():string> $handlers Auftragstyp => Funktion im Bot-Prozess (liefert eine Notiz)
     */
    public function __construct(
        private Database $db,
        private LoopInterface $loop,
        private Settings $settings,
        private array $handlers = []
    ) {
        $this->startedAt = time();
    }

    /** Legt automatisch eine Durchsuchung an, wenn das Intervall abgelaufen ist. */
    private function autoCrawl(): void
    {
        if (!$this->settings->bool('CRAWL_AUTO_ENABLED', false)) {
            return;
        }
        $hours = max(1, $this->settings->int('CRAWL_INTERVAL_HOURS', 24));
        $pdo = $this->db->pdo();

        $open = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE type = 'crawl' AND status IN ('pending','running')")->fetchColumn();
        if ($open > 0) {
            return;
        }
        $last = $pdo->query("SELECT MAX(created_at) FROM jobs WHERE type = 'crawl'")->fetchColumn();
        if ($last && time() - strtotime((string) $last) < $hours * 3600) {
            return;
        }
        // Erste automatische Durchsuchung erst 2 Minuten nach dem Bot-Start
        if (!$last && time() - $this->startedAt < 120) {
            return;
        }
        $pdo->exec("INSERT INTO jobs (type, status, note, created_at) VALUES ('crawl', 'pending', 'automatisch', NOW())");
        echo "[Jobs] Automatische Durchsuchung angelegt (Intervall {$hours} h)\n";
    }

    public function start(): void
    {
        // Nach einem Neustart haengengebliebene Auftraege freigeben
        $this->db->pdo()->exec(
            "UPDATE jobs SET status = 'failed', finished_at = NOW(), note = 'Bot wurde neu gestartet' WHERE status = 'running'"
        );
        $this->tick();
        $this->loop->addPeriodicTimer(self::TICK_SECONDS, function () {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                echo '[Jobs] ' . $e->getMessage() . "\n";
            }
        });
    }

    private function tick(): void
    {
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'INSERT INTO bot_status (k, v, updated_at) VALUES (?,?,NOW())
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()'
        )->execute(['heartbeat', (string) getmypid()]);

        if ($this->proc !== null) {
            $this->checkRunning();
            return;
        }

        if (++$this->ticks % 12 === 1) { // ca. einmal pro Minute
            $this->autoCrawl();
        }

        $row = $pdo->query("SELECT id, type FROM jobs WHERE status = 'pending' ORDER BY id LIMIT 1")->fetch();
        if (!$row) {
            return;
        }
        $id = (int) $row['id'];
        if (isset(self::SCRIPTS[$row['type']])) {
            $this->startScript($id, $row['type']);
        } elseif (isset($this->handlers[$row['type']])) {
            $note = ($this->handlers[$row['type']])();
            $pdo->prepare("UPDATE jobs SET status = 'done', started_at = NOW(), finished_at = NOW(), note = ? WHERE id = ?")
                ->execute([mb_substr($note, 0, 250), $id]);
        } elseif ($row['type'] === 'restart') {
            if (getenv('pm_id') !== false) {
                $pdo->prepare("UPDATE jobs SET status = 'done', started_at = NOW(), finished_at = NOW(), note = ? WHERE id = ?")
                    ->execute(['Neustart ausgelöst', $id]);
                echo "[Jobs] Neustart aus dem Panel angefordert\n";
                $this->loop->addTimer(2, fn() => exit(0));
            } else {
                $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ?")
                    ->execute(['Kein pm2 erkannt - Neustart nicht möglich', $id]);
            }
        } else {
            $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = 'Unbekannter Auftrag' WHERE id = ?")
                ->execute([$row['id']]);
        }
    }

    private function checkRunning(): void
    {
        $status = proc_get_status($this->proc);
        if ($status['running']) {
            return;
        }
        $code = (int) $status['exitcode'];
        proc_close($this->proc);
        $this->proc = null;

        $id = $this->runningId;
        $type = $this->runningType;
        $this->runningId = null;
        $this->runningType = '';

        $ok = $code === 0;
        $note = 'Exit-Code ' . $code;
        $restart = false;

        if ($type === 'update_apply') {
            if ($code === 0) {
                $note = 'Update installiert';
                $restart = true;
            } elseif ($code === 10) {
                $ok = true;
                $note = 'Bereits aktuell';
            } elseif ($code === 2) {
                $note = 'Lokale Änderungen im Projektordner - Update abgebrochen';
            }
        }

        $this->db->pdo()->prepare('UPDATE jobs SET status = ?, finished_at = NOW(), note = ? WHERE id = ?')
            ->execute([$ok ? 'done' : 'failed', $note, $id]);
        echo "[Jobs] Auftrag #{$id} ({$type}) beendet: {$note}\n";

        // Nach einem Update neu starten - pm2 startet den Bot automatisch mit dem neuen Code.
        if ($restart) {
            if (getenv('pm_id') !== false) {
                echo "[Jobs] Starte neu, um das Update zu laden ...\n";
                $this->loop->addTimer(2, fn() => exit(0));
            } else {
                echo "[Jobs] Update installiert. Bitte den Bot manuell neu starten (kein pm2 erkannt).\n";
                $this->db->pdo()->prepare('UPDATE jobs SET note = ? WHERE id = ?')
                    ->execute(['Update installiert - Bot bitte manuell neu starten', $id]);
            }
        }
    }

    private function startScript(int $id, string $type): void
    {
        [$script, $args, $logName] = self::SCRIPTS[$type];
        $root = dirname(__DIR__);
        $log = $root . DIRECTORY_SEPARATOR . $logName;
        $null = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'NUL' : '/dev/null';

        $proc = @proc_open(
            array_merge([PHP_BINARY, $root . DIRECTORY_SEPARATOR . $script], $args),
            [0 => ['file', $null, 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $root
        );
        $pdo = $this->db->pdo();
        if (!is_resource($proc)) {
            $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ?")
                ->execute(['Prozess konnte nicht gestartet werden', $id]);
            echo "[Jobs] Auftrag #{$id} ({$type}) konnte nicht gestartet werden\n";
            return;
        }
        $this->proc = $proc;
        $this->runningId = $id;
        $this->runningType = $type;
        $pdo->prepare("UPDATE jobs SET status = 'running', started_at = NOW() WHERE id = ?")->execute([$id]);
        echo "[Jobs] Auftrag #{$id} ({$type}) gestartet\n";
    }
}
