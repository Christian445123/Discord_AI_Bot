<?php
declare(strict_types=1);

namespace App;

/**
 * Laufzeit-Einstellungen aus dem Web-Panel (Tabelle `settings`).
 * Greifen ohne Neustart; fehlt ein Wert, gilt der Wert aus der .env bzw. der Standardwert.
 */
final class Settings
{
    /** @var array<string,string> */
    private array $cache = [];
    private int $loaded = 0;

    public function __construct(private Database $db)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $this->load();
        return $this->cache[$key] ?? Env::get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = strtolower($this->get($key, $default ? 'true' : 'false'));
        return in_array($v, ['1', 'true', 'yes', 'ja', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        return (int) $this->get($key, (string) $default);
    }

    private function load(): void
    {
        if ($this->loaded !== 0 && time() - $this->loaded < 5) {
            return;
        }
        try {
            $this->cache = $this->db->pdo()->query('SELECT k, v FROM settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            // Cache behalten
        }
        $this->loaded = time();
    }
}
