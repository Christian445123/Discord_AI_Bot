<?php
declare(strict_types=1);

namespace App;

/**
 * Bewertet die "Hitze" eines Channels anhand der letzten Nachrichten.
 * Greift nie mit Strafen ein - nur mit deeskalierenden Hinweisen und Meldung ans Team.
 */
final class Moderator
{
    private const DEFAULT_BAD_WORDS = [
        'idiot', 'depp', 'arschloch', 'hurensohn', 'huren', 'fotze', 'wichser', 'spast', 'spasti', 'behindert', 'opfer',
        'vollidiot', 'trottel', 'dumm', 'dummkopf', 'schwachsinn', 'halt die fresse', 'halts maul', 'maul', 'fresse',
        'scheisse', 'scheiss', 'kacke', 'verpiss', 'noob', 'lappen', 'penner', 'schlampe', 'bastard', 'kauf dir ein leben',
    ];

    private const WINDOW_SECONDS = 90;

    /** @var array<string,array<int,array{t:int,user:string,heat:int}>> */
    private array $buffer = [];
    /** @var array<string,int> */
    private array $lastIntervention = [];
    /** @var array<string,int[]> */
    private array $interventionHistory = [];
    /** @var string[] */
    private array $badWords;

    public function __construct()
    {
        $extra = array_map(fn($w) => Text::normalize($w), Env::list('EXTRA_BAD_WORDS'));
        $this->badWords = array_values(array_unique(array_merge(self::DEFAULT_BAD_WORDS, $extra)));
    }

    public function heatOf(string $raw): int
    {
        $norm = Text::normalize($raw);
        $heat = 0;
        foreach ($this->badWords as $w) {
            if ($w !== '' && preg_match('/\b' . preg_quote($w, '/') . '\b/u', $norm)) {
                $heat += 3;
            }
        }
        $letters = preg_replace('/[^A-Za-zÄÖÜäöü]/u', '', $raw) ?? '';
        if (mb_strlen($letters) >= 12) {
            $upper = mb_strlen(preg_replace('/[^A-ZÄÖÜ]/u', '', $letters) ?? '');
            if ($upper / mb_strlen($letters) > 0.7) {
                $heat += 2;
            }
        }
        if (preg_match('/[!?]{3,}/', $raw)) {
            $heat += 1;
        }
        return $heat;
    }

    /**
     * Nimmt eine Nachricht auf und liefert bei Eskalation Infos zurueck.
     *
     * @return array{heat:int,users:string[],repeat:bool}|null
     */
    public function observe(string $channelId, string $userId, string $raw, int $now): ?array
    {
        $heat = $this->heatOf($raw);
        $buf = $this->buffer[$channelId] ?? [];
        $buf = array_values(array_filter($buf, fn($m) => $now - $m['t'] <= self::WINDOW_SECONDS));
        $buf[] = ['t' => $now, 'user' => $userId, 'heat' => $heat];
        $this->buffer[$channelId] = array_slice($buf, -40);

        if ($heat === 0) {
            return null;
        }

        $total = array_sum(array_column($buf, 'heat'));
        $users = array_values(array_unique(array_column(array_filter($buf, fn($m) => $m['heat'] > 0), 'user')));
        // Streit zwischen mehreren Personen zaehlt staerker als ein einzelner Ausrutscher.
        if (count($users) >= 2) {
            $total += 2;
        }

        if ($total < Env::int('HEAT_THRESHOLD', 6)) {
            return null;
        }
        $cooldown = Env::int('INTERVENTION_COOLDOWN_SECONDS', 120);
        if (isset($this->lastIntervention[$channelId]) && $now - $this->lastIntervention[$channelId] < $cooldown) {
            return null;
        }

        $this->lastIntervention[$channelId] = $now;
        $hist = array_filter($this->interventionHistory[$channelId] ?? [], fn($t) => $now - $t <= 900);
        $hist[] = $now;
        $this->interventionHistory[$channelId] = array_values($hist);
        $this->buffer[$channelId] = [];

        return ['heat' => $total, 'users' => $users, 'repeat' => count($hist) >= 2];
    }
}
