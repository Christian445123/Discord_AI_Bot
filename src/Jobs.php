<?php
declare(strict_types=1);

namespace App;

use React\EventLoop\LoopInterface;

/**
 * Wird im dauerhaft laufenden Bot (pm2) betrieben:
 *  - schreibt regelmaessig einen Heartbeat (Panel zeigt "Bot online"),
 *  - fuehrt vom Web-Panel angeforderte Auftraege aus (z.B. Server-Durchsuchung).
 */
final class Jobs
{
    private const TICK_SECONDS = 5;

    /** @var resource|null */
    private $proc = null;
    private ?int $runningId = null;

    public function __construct(private Database $db, private LoopInterface $loop)
    {
    }

    public function start(): void
    {
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

        // laufenden Auftrag pruefen
        if ($this->proc !== null) {
            $status = proc_get_status($this->proc);
            if (!$status['running']) {
                $code = (int) $status['exitcode'];
                proc_close($this->proc);
                $this->proc = null;
                $pdo->prepare('UPDATE jobs SET status = ?, finished_at = NOW(), note = ? WHERE id = ?')
                    ->execute([$code === 0 ? 'done' : 'failed', 'Exit-Code ' . $code, $this->runningId]);
                echo "[Jobs] Auftrag #{$this->runningId} beendet (Exit-Code {$code})\n";
                $this->runningId = null;
            }
            return;
        }

        // Nach einem Neustart haengengebliebene Auftraege freigeben
        $pdo->exec("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = 'Bot wurde neu gestartet' WHERE status = 'running'");

        $row = $pdo->query("SELECT id, type FROM jobs WHERE status = 'pending' ORDER BY id LIMIT 1")->fetch();
        if (!$row) {
            return;
        }
        if ($row['type'] === 'crawl') {
            $this->startCrawl((int) $row['id']);
        } else {
            $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = 'Unbekannter Auftrag' WHERE id = ?")
                ->execute([$row['id']]);
        }
    }

    private function startCrawl(int $id): void
    {
        $root = dirname(__DIR__);
        $log = $root . DIRECTORY_SEPARATOR . 'crawl.log';
        $null = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'NUL' : '/dev/null';

        $proc = @proc_open(
            [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'crawl.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $root
        );
        $pdo = $this->db->pdo();
        if (!is_resource($proc)) {
            $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = NOW(), note = ? WHERE id = ?")
                ->execute(['Prozess konnte nicht gestartet werden', $id]);
            echo "[Jobs] Durchsuchung konnte nicht gestartet werden\n";
            return;
        }
        $this->proc = $proc;
        $this->runningId = $id;
        $pdo->prepare("UPDATE jobs SET status = 'running', started_at = NOW() WHERE id = ?")->execute([$id]);
        echo "[Jobs] Server-Durchsuchung gestartet (Auftrag #{$id})\n";
    }
}
