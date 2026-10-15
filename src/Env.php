<?php
declare(strict_types=1);

namespace App;

final class Env
{
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
