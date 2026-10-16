<?php
declare(strict_types=1);

namespace App;

final class Env
{
    /**
     * Laedt die .env. Werte aus der .env haben Vorrang vor Umgebungsvariablen
     * (z.B. von pm2 gespeichert) - so gilt immer, was in der .env steht. Schlaegt der strenge Dotenv-Parser fehl (z.B. Wert mit Leerzeichen ohne Anfuehrungszeichen),
     * wird die Datei tolerant eingelesen, statt dass der Bot nicht mehr startet.
     */
    public static function load(string $dir): void
    {
        try {
            \Dotenv\Dotenv::createMutable($dir)->safeLoad();
            return;
        } catch (\Throwable $e) {
            fwrite(STDERR, '[Env] Warnung: .env enthaelt einen Fehler (' . $e->getMessage()
                . ") - lese tolerant. Bitte Werte mit Leerzeichen in Anfuehrungszeichen setzen.\n");
        }

        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.env';
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = stripcslashes(substr($v, 1, -1));
            }
            if ($k !== "") {
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
    }

    /**
     * Ergaenzt Einstellungen, die in der .env fehlen (z.B. nach einem Update oder bei aelteren Installationen),
     * mit den Werten und Kommentaren aus der .env.example. Vorhandene Werte werden nie veraendert.
     *
     * @return string[] Namen der ergaenzten Einstellungen
     */
    public static function addMissingKeys(string $dir): array
    {
        $envFile = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.env';
        $exFile = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.env.example';
        if (!is_file($envFile) || !is_file($exFile) || !is_writable($envFile)) {
            return [];
        }

        $have = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=/', $line, $m)) {
                $have[$m[1]] = true;
            }
        }

        $added = [];
        $out = [];
        $comment = [];
        foreach (file($exFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trim = trim($line);
            if ($trim !== '' && $trim[0] === '#') {
                $comment[] = $line;
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+)=(.*)$/', $trim, $m)) {
                if (!isset($have[$m[1]])) {
                    $added[$m[1]] = $m[2];
                    $out = array_merge($out, $comment, [$trim]);
                }
            }
            $comment = [];
        }

        if ($added === []) {
            return [];
        }
        $block = PHP_EOL . '# --- automatisch ergaenzt (' . date('Y-m-d H:i') . ') ---' . PHP_EOL . implode(PHP_EOL, $out) . PHP_EOL;
        if (@file_put_contents($envFile, $block, FILE_APPEND | LOCK_EX) === false) {
            return [];
        }
        foreach ($added as $k => $v) {
            if (!isset($_ENV[$k]) && $v !== '') {
                $_ENV[$k] = trim($v, "'\"");
            }
        }
        return array_keys($added);
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : (string) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(self::get($key, $default ? 'true' : 'false'));
        return in_array($v, ['1', 'true', 'yes', 'ja', 'on'], true);
    }

    public static function float(string $key, float $default): float
    {
        return (float) self::get($key, (string) $default);
    }

    public static function int(string $key, int $default): int
    {
        return (int) self::get($key, (string) $default);
    }

    /** @return string[] */
    public static function list(string $key): array
    {
        $raw = self::get($key);
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($s) => $s !== ''));
    }
}
