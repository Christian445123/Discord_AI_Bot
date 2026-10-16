<?php
declare(strict_types=1);

namespace App;

use React\EventLoop\LoopInterface;

/**
 * Startet das Web-Panel als Kindprozess des Bots (php -S). So gibt es nur EINEN pm2-Prozess:
 * Wird der Bot gestartet/gestoppt/neu gestartet, passiert das mit dem Panel automatisch.
 * Stirbt der Panel-Prozess, startet ihn der Bot neu.
 */
final class PanelServer
{
    /** @var resource|null */
    private $proc = null;
    private int $lastStart = 0;
    private string $root;

    public function __construct(private LoopInterface $loop)
    {
        $this->root = dirname(__DIR__);
    }

    public function start(): void
    {
        if (!Env::bool('PANEL_EMBEDDED', true)) {
            echo "[Panel] Eingebettetes Panel ist deaktiviert (PANEL_EMBEDDED=false)\n";
            return;
        }
        if (Env::get('PANEL_PASSWORD_HASH') === '') {
            echo "[Panel] PANEL_PASSWORD_HASH fehlt in der .env - Panel wird nicht gestartet (install.php ausfuehren)\n";
            return;
        }

        $this->killStale();
        $this->spawn();

        $this->loop->addPeriodicTimer(10, function () {
            if ($this->proc !== null && !proc_get_status($this->proc)['running']) {
                echo "[Panel] Prozess beendet - starte neu\n";
                @proc_close($this->proc);
                $this->proc = null;
            }
            if ($this->proc === null && time() - $this->lastStart >= 10) {
                $this->spawn();
            }
        });

        // Beim Beenden des Bots (pm2 stop/restart) das Panel mitbeenden
        register_shutdown_function(fn() => $this->stop());
        foreach (['SIGTERM', 'SIGINT'] as $sig) {
            if (defined($sig)) {
                try {
                    $this->loop->addSignal(constant($sig), function () {
                        $this->stop();
                        exit(0);
                    });
                } catch (\Throwable $e) {
                    // pcntl nicht verfuegbar - shutdown function genuegt
                }
            }
        }
    }

    private function spawn(): void
    {
        $this->lastStart = time();
        $port = $this->pickPort();
        $log =$this->root . DIRECTORY_SEPARATOR . 'panel.log';
        $windows = stripos(PHP_OS_FAMILY, 'Windows') === 0;

        $env = null;
        if (!$windows) {
            $env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']); // parallele Anfragen
        }

        $proc = @proc_open(
            [PHP_BINARY, '-S', '0.0.0.0:' . $port, '-t', $this->root . DIRECTORY_SEPARATOR . 'panel'],
            [0 => ['file', $windows ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $this->root,
            $env
        );
        if (!is_resource($proc)) {
            echo "[Panel] Konnte nicht gestartet werden (proc_open gesperrt?)\n";
            return;
        }
        $this->proc = $proc;
        $pid = (int) (proc_get_status($proc)['pid'] ?? 0);
        @file_put_contents($this->root . DIRECTORY_SEPARATOR . 'panel.pid', (string) $pid);
        echo "[Panel] Web-Panel laeuft auf Port {$port} (PID {$pid}, Log: panel.log)\n";
    }

    /** Port aus PANEL_PORT (.env). Ist er belegt, wird der naechste freie genommen und darauf hingewiesen. */
    private function pickPort(): int
    {
        $wanted = max(1, Env::int('PANEL_PORT', 8080));
        for ($p = $wanted; $p < $wanted + 20; $p++) {
            $s = @stream_socket_server('tcp://0.0.0.0:' . $p, $errno, $errstr);
            if ($s !== false) {
                fclose($s);
                if ($p !== $wanted) {
                    echo "[Panel] Port {$wanted} ist belegt - verwende stattdessen {$p}. Fest einstellen: PANEL_PORT={$p} in der .env\n";
                }
                return $p;
            }
        }
        echo "[Panel] Kein freier Port ab {$wanted} gefunden - bitte PANEL_PORT in der .env aendern\n";
        return $wanted;
    }

    private function stop(): void
    {
        if ($this->proc !== null) {
            @proc_terminate($this->proc);
            $this->proc = null;
        }
        @unlink($this->root . DIRECTORY_SEPARATOR . 'panel.pid');
    }

    /** Ein uebrig gebliebener Panel-Prozess eines frueheren Starts wuerde den Port blockieren. */
    private function killStale(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'panel.pid';
        $pid = is_file($file) ? (int) file_get_contents($file) : 0;
        if ($pid <= 1) {
            return;
        }
        if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
            @exec('taskkill /F /PID ' . $pid . ' 2>NUL');
        } elseif (function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        } else {
            @exec('kill ' . $pid . ' 2>/dev/null');
        }
        usleep(300000);
        @unlink($file);
    }
}
