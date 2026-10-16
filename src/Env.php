<?php
declare(strict_types=1);

namespace App;

final class Env
{
    /**
     * Laedt die .env. Schlaegt der strenge Dotenv-Parser fehl (z.B. Wert mit Leerzeichen ohne Anfuehrungszeichen),
     * wird die Datei tolerant eingelesen, statt dass der Bot nicht mehr startet.
     */
    public static function load(string $dir): void
    {
        try {
            \Dotenv\Dotenv::createImmutable($dir)->safeLoad();
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
            if ($k !== '' && !isset($_ENV[$k])) {
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
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
