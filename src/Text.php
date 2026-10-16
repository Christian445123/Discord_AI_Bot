<?php
declare(strict_types=1);

namespace App;

/** Textnormalisierung und Aehnlichkeitsberechnung (deutsch). */
final class Text
{
    private const STOPWORDS = [
        'der','die','das','den','dem','des','ein','eine','einen','einem','einer','und','oder','aber','ich','du','er','sie','es','wir','ihr',
        'mir','mich','dir','dich','mein','meine','meinen','dein','deine','ist','sind','war','bin','bist','hat','habe','haben','kann','koennte',
        'kannst','koennen','will','moechte','mal','mit','bei','von','zu','zum','zur','im','in','am','an','auf','fuer','wie','was','wo','wer',
        'wann','warum','wieso','welche','welcher','gibt','denn','doch','auch','noch','nur','so','ja','nein','bitte','hallo','hi','hey','moin',
        'kurze','frage','also','dass','ob','man','nicht','kein','keine','um','als','wenn','nach','vor','aus',
    ];

    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/<@[!&]?\d+>|<#\d+>|https?:\/\/\S+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** Eingebaute Wortgruppen (gleiche Bedeutung = gleicher Begriff); erweiterbar ueber SYNONYMS in der .env ("fahrzeug=auto,wagen=auto"). */
    private const SYNONYMS = [
        'fahrzeug' => 'auto', 'wagen' => 'auto', 'pkw' => 'auto', 'karre' => 'auto', 'autos' => 'auto', 'fahrzeuge' => 'auto',
        'wohnung' => 'immobilie', 'haus' => 'immobilie', 'apartment' => 'immobilie', 'immo' => 'immobilie',
        'arbeit' => 'job', 'beruf' => 'job', 'arbeiten' => 'job',
        'bewerben' => 'bewerbung', 'bewirb' => 'bewerbung',
        'melden' => 'support', 'hilfe' => 'support', 'ticket' => 'support',
        'regeln' => 'regelwerk', 'regel' => 'regelwerk',
        'geld' => 'bargeld', 'cash' => 'bargeld',
        'verbinden' => 'joinen', 'beitreten' => 'joinen', 'einsteigen' => 'joinen',
    ];

    /** @var array<string,string>|null */
    private static ?array $synonyms = null;

    private static function canonical(string $w): string
    {
        if (self::$synonyms === null) {
            self::$synonyms = self::SYNONYMS;
            foreach (Env::list('SYNONYMS') as $pair) {
                [$from, $to] = array_pad(explode('=', $pair, 2), 2, '');
                $from = self::normalize($from);
                $to = self::normalize($to);
                if ($from !== '' && $to !== '') {
                    self::$synonyms[$from] = $to;
                }
            }
        }
        return self::$synonyms[$w] ?? $w;
    }

    /** @return string[] */
    public static function tokens(string $normalized): array
    {
        $out = [];
        foreach (explode(' ', $normalized) as $w) {
            if ($w === '' || in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            $w = self::canonical($w);                                  // gleichbedeutende Begriffe zusammenfuehren
            $w = preg_replace('/(en|er|es|e|n|s|t)$/', '', $w) ?? $w; // einfaches Stemming
            if (strlen($w) >= 2) {
                $out[$w] = true;
            }
        }
        return array_keys($out);
    }

    /** @return array<string,bool> */
    private static function trigrams(string $s): array
    {
        $s = ' ' . $s . ' ';
        $g = [];
        for ($i = 0, $n = strlen($s) - 2; $i < $n; $i++) {
            $g[substr($s, $i, 3)] = true;
        }
        return $g;
    }

    /** Aehnlichkeit 0..1: Token-Jaccard + Zeichen-Trigramm-Dice. */
    public static function similarity(string $normA, string $normB): float
    {
        $ta = self::tokens($normA);
        $tb = self::tokens($normB);
        if (!$ta || !$tb) {
            return 0.0;
        }
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        $jaccard = $union ? $inter / $union : 0.0;

        $ga = self::trigrams($normA);
        $gb = self::trigrams($normB);
        $gi = count(array_intersect_key($ga, $gb));
        $sum = count($ga) + count($gb);
        $dice = $sum ? (2 * $gi) / $sum : 0.0;

        return 0.6 * $jaccard + 0.4 * $dice;
    }

    public static function looksLikeQuestion(string $raw, string $normalized): bool
    {
        if (mb_strlen($raw) < 8) {
            return false;
        }
        if (str_contains($raw, '?')) {
            return true;
        }
        return (bool) preg_match('/^(wie|was|wo|wer|wann|warum|wieso|weshalb|welche[rsmn]?|wohin|woher|kann|koennt|gibt|darf|muss|ist|sind|habt|hat)\b/', $normalized);
    }
}
