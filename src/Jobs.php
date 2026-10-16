<?php
declare(strict_types=1);

namespace App;

use React\EventLoop\LoopInterface;

/**
 * Wird im dauerhaft laufenden Bot (pm2) betrieben:
 *  - schreibt regelmaessig einen Heartbeat (Panel zeigt "Bot online"),
 *  - fuehrt vom Web-Panel angeforderte Auftraege aus (Server-Durchsuchung, GitHub-Update ...),
 *  - legt bei Bedarf automatisch Durchsuchungen an.
 *
 * Laeuft der Bot nicht, startet das Panel Auftraege selbst ueber run_job.php (siehe panel/bootstrap.php).
 */
final class Jobs
{
    private const TICK_SECONDS = 5;

    /** type => [script, args, logfile] */
    public const SCRIPTS = [
        'crawl' => ['crawl.php', [], 'crawl.log'],
        'crawl_full' => ['crawl.php', ['full'], 'crawl.log'],
        'sync_channels' => ['sync.php', [], 'sync.log'],
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

    /**
     * Bewertet das Ende eines Auftragsskripts.
     *
     * @return array{0:bool,1:string,2:bool} [erfolgreich, Notiz, Bot-Neustart noetig]
     */
    public static function outcome(string $type, int $code): array
    {
        if ($type === 'update_apply') {
            return match ($code) {
                0 => [true, 'Update installiert', true],
                10 => [true, 'Bereits aktuell', false],
                2 => [false, 'Lokale Änderungen im Projektordner - Update abgebrochen', false],
                default => [false, 'Exit-Code ' . $code, false],
            };
        }
        return [$code === 0, 'Exit-Code ' . $code, false];
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

    /** Legt automatisch eine Durchsuchung an, wenn das Intervall abgelaufen ist. */
    private function autoCrawl(): void
    {
        if (!$this->settings->bool('CRAWL_AUTO_ENABLED', false)) {
            return;
        }
        $hours = max(1, $this->settings->int('CRAWL_INTERVAL_HOURS', 24));
        $pdo = $this->db->pdo();

        $open = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE type IN ('crawl','crawl_full') AND status IN ('pending','running')")->fetchColumn();
        if ($open > 0) {
            return;
        }
        $last = $pdo->query("SELECT MAX(created_at) FROM jobs WHERE type IN ('crawl','crawl_full')")->fetchColumn();
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
        $type = (string) $row['type'];

        if (isset($this->handlers[$type])) {
            if (!$this->claim($id)) {
                return;
            }
            $note = ($this->handlers[$type])();
            $pdo->prepare("UPDATE jobs SET status = 'done', finished_at = NOW(), note = ? WHERE id = ?")
                ->execute([mb_substr($note, 0, 250), $id]);
        } elseif (isset(self::SCRIPTS[$type])) {
            $this->startScript($id, $type);
        } elseif ($type === 'restart') {
            if (!$this->claim($id)) {
                return;
            }
            if (getenv('pm_id') !== false) {
                $pdo->prepare("UPDATE jobs SET status = 'done', finished_at = NOW(), note = ? WHERE id = ?")
                    ->execute(['Neustart ausgelöst', $id]);
                echo "[Jobs] Neustart aus dem Panel angefordert\n";
                $this->loop->addTimer(2, fn() => exit(0));
            } else {
                $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ?")
                    ->execute(['Kein pm2 erkannt - Neustart nicht möglich', $id]);
            }
        } else {
            $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = 'Unbekannter Auftrag' WHERE id = ?")
                ->execute([$id]);
        }
    }

    /** Auftrag atomar uebernehmen (verhindert Doppelausfuehrung, falls das Panel ihn gleichzeitig startet). */
    private function claim(int $id): bool
    {
        $st = $this->db->pdo()->prepare("UPDATE jobs SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'pending'");
        $st->execute([$id]);
        return $st->rowCount() === 1;
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

        $id = (int) $this->runningId;
        $type = $this->runningType;
        $this->runningId = null;
        $this->runningType = '';

        [$ok, $note, $restart] = self::outcome($type, $code);

        // Nach einem Update neu starten - pm2 startet den Bot automatisch mit dem neuen Code.
        $canRestart = $restart && getenv('pm_id') !== false;
        if ($restart && !$canRestart) {
            $note = 'Update installiert - Bot bitte manuell neu starten';
        }

        $this->db->pdo()->prepare('UPDATE jobs SET status = ?, finished_at = NOW(), note = ? WHERE id = ?')
            ->execute([$ok ? 'done' : 'failed', $note, $id]);
        echo "[Jobs] Auftrag #{$id} ({$type}) beendet: {$note}\n";

        if ($canRestart) {
            echo "[Jobs] Starte neu, um das Update zu laden ...\n";
            $this->loop->addTimer(2, fn() => exit(0));
        }
    }

    private function startScript(int $id, string $type): void
    {
        if (!$this->claim($id)) {
            return; // vom Panel bereits uebernommen
        }
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
        if (!is_resource($proc)) {
            $this->db->pdo()->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ?")
                ->execute(['Prozess konnte nicht gestartet werden', $id]);
            echo "[Jobs] Auftrag #{$id} ({$type}) konnte nicht gestartet werden\n";
            return;
        }
        $this->proc = $proc;
        $this->runningId = $id;
        $this->runningType = $type;
        echo "[Jobs] Auftrag #{$id} ({$type}) gestartet\n";
    }
}
