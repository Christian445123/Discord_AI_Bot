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
     * Gleicht die .env mit der .env.example ab: gleiche Einstellungen, gleiche Reihenfolge, gleiche Kommentare.
     * Vorhandene Werte der .env bleiben unveraendert; fehlende Einstellungen werden mit dem Standardwert aus der
     * .env.example ergaenzt. Eigene Zusatzeintraege der .env bleiben am Ende erhalten.
     * Vor einer Aenderung wird eine Sicherung als .env.backup angelegt.
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
        $oldContent = (string) file_get_contents($envFile);

        $values = [];
        foreach (preg_split('/\R/', $oldContent) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=(.*)$/', $line, $m) && !array_key_exists($m[1], $values)) {
                $values[$m[1]] = rtrim($m[2]);
            }
        }

        $out = [];
        $added = [];
        $inExample = [];
        foreach (preg_split('/\R/', rtrim((string) file_get_contents($exFile))) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_]+)=(.*)$/', trim($line), $m)) {
                $inExample[$m[1]] = true;
                if (array_key_exists($m[1], $values)) {
                    $out[] = $m[1] . '=' . $values[$m[1]];
                } else {
                    $out[] = $m[1] . '=' . $m[2];
                    $added[] = $m[1];
                    if ($m[2] !== '') {
                        $_ENV[$m[1]] = trim($m[2], "'\"");
                    }
                }
            } else {
                $out[] = rtrim($line); // Kommentare und Leerzeilen
            }
        }

        $extra = array_diff_key($values, $inExample);
        if ($extra !== []) {
            $out[] = '';
            $out[] = '# --- weitere Einstellungen (nicht in der .env.example) ---';
            foreach ($extra as $k => $v) {
                $out[] = $k . '=' . $v;
            }
        }

        $new = implode(PHP_EOL, $out) . PHP_EOL;
        $norm = fn(string $s) => str_replace("\r\n", "\n", $s);
        if ($norm($new) === $norm($oldContent)) {
            return [];
        }

        @copy($envFile, $envFile . '.backup');
        @chmod($envFile . '.backup', 0600);
        if (@file_put_contents($envFile, $new, LOCK_EX) === false) {
            return [];
        }
        return $added;
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
