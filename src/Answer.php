<?php
declare(strict_types=1);

namespace App;

/**
 * Baut aus den gefundenen Wissensstuecken (gelernte Team-Antworten und Server-Infos) eine hoefliche Antwort.
 * Rein regelbasiert, ohne KI-Modell: Saetze aus dem Wissen werden danach bewertet, wie gut sie zur Frage passen,
 * die besten werden ausgewaehlt, von Doppelungen befreit und in eine freundliche Antwort eingebettet.
 */
final class Answer
{
    private const MAX_CHARS = 900;
    private const MAX_SENTENCES = 4;

    /**
     * @param array<int,array{type:string,id:int,text:string,channel:string,score:float}> $context beste Treffer, bester zuerst
     */
    public static function compose(Knowledge $knowledge, array $context, string $question, string $name, bool $sie, string $server): string
    {
        $qTokens = Text::tokens(Text::normalize($question));
        $idf = $knowledge->weights($qTokens);

        [$body, $channels, $fromTeam] = self::selectBody($context, $qTokens, $idf);

        $lead = $fromTeam
            ? 'Das Team hat dazu bereits Folgendes mitgeteilt:'
            : (count($channels) === 1 ? "Dazu steht im Channel #{$channels[0]} Folgendes:" : 'Dazu habe ich Folgendes gefunden:');

        $seed = crc32(Text::normalize($question));
        $greet = $sie
            ? self::pick($seed, ["Hallo {$name}, vielen Dank für Ihre Frage!", "Guten Tag {$name}, danke für Ihre Nachricht!", "Hallo {$name}, gerne helfe ich Ihnen weiter!"])
            : self::pick($seed, ["Hallo {$name}, danke für deine Frage!", "Hallo {$name}, gerne helfe ich dir weiter!", "Hi {$name}, danke für deine Nachricht!"]);
        $closing = $sie
            ? self::pick($seed >> 3, [
                'Falls noch etwas offen ist, melden Sie sich gerne – ein Teammitglied hilft Ihnen bei Bedarf weiter.',
                'Sollte das Ihre Frage nicht vollständig beantworten, schaut gerne noch ein Teammitglied vorbei.',
                'Bei weiteren Fragen sind wir jederzeit für Sie da.',
            ])
            : self::pick($seed >> 3, [
                'Falls noch etwas offen ist, melde dich gerne – ein Teammitglied hilft dir bei Bedarf weiter.',
                'Sollte das deine Frage nicht vollständig beantworten, schaut gerne noch ein Teammitglied vorbei.',
                'Bei weiteren Fragen sind wir jederzeit für dich da.',
            ]);

        return "{$greet} {$lead}\n\n{$body}\n\n{$closing} – {$server}";
    }

    /** @return array{0:string,1:string[],2:bool} [Text, Channels, stammt direkt vom Team] */
    private static function selectBody(array $context, array $qTokens, array $idf): array
    {
        $top = $context[0];

        // Sehr aehnliche Frage wurde schon vom Team beantwortet: diese Antwort im Original wiedergeben
        if ($top['type'] === 'qa' && $top['score'] >= 0.85) {
            return [trim(mb_strimwidth($top['text'], 0, self::MAX_CHARS, '…')), [], true];
        }

        $total = array_sum($idf) ?: 1.0;
        $cands = [];
        foreach ($context as $rank => $c) {
            foreach (self::sentences($c['text']) as $pos => $s) {
                $tok = array_flip(Text::tokens(Text::normalize($s)));
                $hit = 0.0;
                foreach ($idf as $t => $w) {
                    if (isset($tok[$t])) {
                        $hit += $w;
                    }
                }
                $score = $hit / $total;
                if ($c['type'] === 'qa') {
                    $score *= 1.15;                 // Antworten des Teams etwas hoeher gewichten
                }
                $score *= 1 - 0.08 * $rank;          // bessere Treffer bevorzugen
                if (mb_strlen($s) > 300) {
                    $score *= 0.9;
                }
                $cands[] = ['s' => $s, 'score' => $score, 'rank' => $rank, 'pos' => $pos, 'channel' => $c['channel'], 'type' => $c['type']];
            }
        }

        $best = $cands ? max(array_column($cands, 'score')) : 0.0;
        if ($best <= 0.0) {
            // Kein einzelner Satz passt klar: Anfang des besten Treffers verwenden
            $first = array_slice(self::sentences($top['text']), 0, 2);
            return [mb_strimwidth(implode(' ', $first), 0, self::MAX_CHARS, '…'), $top['channel'] !== '' ? [$top['channel']] : [], $top['type'] === 'qa'];
        }

        usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
        $chosen = [];
        $len = 0;
        foreach ($cands as $c) {
            if ($c['score'] < 0.4 * $best || count($chosen) >= self::MAX_SENTENCES) {
                break;
            }
            foreach ($chosen as $o) {
                if (Text::similarity(Text::normalize($c['s']), Text::normalize($o['s'])) >= 0.8) {
                    continue 2; // Doppelung
                }
            }
            if ($len + mb_strlen($c['s']) > self::MAX_CHARS && $chosen !== []) {
                continue;
            }
            $chosen[] = $c;
            $len += mb_strlen($c['s']);
        }

        // zurueck in die urspruengliche Reihenfolge (Treffer-Rang, dann Position im Text)
        usort($chosen, fn($a, $b) => [$a['rank'], $a['pos']] <=> [$b['rank'], $b['pos']]);
        $texts = array_map(fn($c) => $c['s'], $chosen);
        $body = count($texts) >= 3 ? '• ' . implode("\n• ", $texts) : implode(' ', $texts);

        $channels = array_values(array_unique(array_filter(array_column($chosen, 'channel'))));
        $fromTeam = $chosen !== [] && count(array_unique(array_column($chosen, 'type'))) === 1 && $chosen[0]['type'] === 'qa';

        return [mb_strimwidth($body, 0, self::MAX_CHARS + 60, '…'), $channels, $fromTeam];
    }

    /** @return string[] */
    private static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\R+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim(preg_replace('/^[\-\*•]\s+/u', '', trim($p)) ?? $p);
            if (mb_strlen($p) >= 12) {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function pick(int $seed, array $options): string
    {
        return $options[abs($seed) % count($options)];
    }
}
