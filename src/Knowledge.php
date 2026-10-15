<?php
declare(strict_types=1);

namespace App;

/** Lernspeicher: Frage -> Teamler-Antwort. */
final class Knowledge
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $cache = null;
    private int $cacheTime = 0;

    public function __construct(private Database $db)
    {
    }

    public function learn(string $question, string $answer, string $teamUserId, string $channelId): void
    {
        $question = trim($question);
        $answer = trim($answer);
        $norm = Text::normalize($question);
        if ($norm === '' || mb_strlen($answer) < 3 || count(Text::tokens($norm)) < 1) {
            return;
        }

        // Fast identische Frage vorhanden? -> Antwort aktualisieren statt duplizieren.
        foreach ($this->all() as $row) {
            if (Text::similarity($norm, $row['normalized']) >= 0.9) {
                $st = $this->db->pdo()->prepare(
                    'UPDATE knowledge SET answer = ?, answered_by = ?, created_at = ?, score = score + 1 WHERE id = ?'
                );
                $st->execute([$answer, $teamUserId, date('Y-m-d H:i:s'), $row['id']]);
                $this->cache = null;
                return;
            }
        }

        $st = $this->db->pdo()->prepare(
            'INSERT INTO knowledge (question, normalized, answer, answered_by, channel_id, created_at) VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$question, $norm, $answer, $teamUserId, $channelId, date('Y-m-d H:i:s')]);
        // Cache direkt ergaenzen statt neu zu laden (wichtig fuer grosse Server-Durchsuchungen).
        $this->all();
        $this->cache[] = ['id' => (int) $this->db->pdo()->lastInsertId(), 'normalized' => $norm, 'answer' => $answer, 'score' => 0];
    }

    /** Speichert eine Info-Nachricht aus einem oeffentlichen Channel (Regeln, Ankuendigungen, FAQ ...). */
    public function addInfo(string $messageId, string $channelId, string $channelName, string $content, string $createdAt): void
    {
        $st = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO server_info (message_id, channel_id, channel_name, content, normalized, created_at) VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$messageId, $channelId, $channelName, $content, Text::normalize($content), $createdAt]);
    }

    /**
     * Sucht in den gespeicherten Server-Infos nach einem Text, der die Frage inhaltlich abdeckt.
     *
     * @return array{content:string,channel:string}|null
     */
    public function findInfo(string $question, float $minCoverage = 0.75): ?array
    {
        $qTokens = Text::tokens(Text::normalize($question));
        if (count($qTokens) < 2) {
            return null;
        }
        $best = null;
        $bestScore = 0.0;
        $rows = $this->db->pdo()->query('SELECT content, channel_name, normalized FROM server_info ORDER BY id DESC LIMIT 5000');
        foreach ($rows as $row) {
            $iTokens = Text::tokens($row['normalized']);
            $hit = count(array_intersect($qTokens, $iTokens));
            $score = $hit / count($qTokens);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
        if ($best === null || $bestScore < $minCoverage) {
            return null;
        }
        return ['content' => $best['content'], 'channel' => $best['channel_name']];
    }

    /** @return array{id:int,answer:string,confidence:float}|null */
    public function find(string $question, float $minConfidence): ?array
    {
        $norm = Text::normalize($question);
        $best = null;
        $bestScore = 0.0;
        foreach ($this->all() as $row) {
            if ((int) $row['score'] < -2) {
                continue; // vom Team mehrfach korrigiert
            }
            $s = Text::similarity($norm, $row['normalized']);
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $row;
            }
        }
        if ($best === null || $bestScore < $minConfidence) {
            return null;
        }
        return ['id' => (int) $best['id'], 'answer' => $best['answer'], 'confidence' => $bestScore];
    }

    public function markUsed(int $id): void
    {
        $st = $this->db->pdo()->prepare('UPDATE knowledge SET uses = uses + 1, last_used_at = ? WHERE id = ?');
        $st->execute([date('Y-m-d H:i:s'), $id]);
    }

    /** Teamler hat eine Bot-Antwort korrigiert. */
    public function penalize(int $id): void
    {
        $st = $this->db->pdo()->prepare('UPDATE knowledge SET score = score - 1 WHERE id = ?');
        $st->execute([$id]);
        $this->cache = null;
    }

    /** @return array<int,array<string,mixed>> */
    private function all(): array
    {
        // Cache nur kurz halten, damit Aenderungen aus dem Web-Panel uebernommen werden.
        if ($this->cache === null || time() - $this->cacheTime > 30) {
            $this->cache = $this->db->pdo()
                ->query('SELECT id, normalized, answer, score FROM knowledge')
                ->fetchAll();
            $this->cacheTime = time();
        }
        return $this->cache;
    }
}
