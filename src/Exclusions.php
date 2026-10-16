<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Welche Channels duerfen NICHT als Wissensquelle dienen?
 *  - im Panel ausgenommene Channels und alle Channels ausgenommener Kategorien
 *  - automatisch erkannte Team-Channels (nicht oeffentlich)
 *  - CRAWL_IGNORE_CHANNEL_IDS aus der .env
 */
final class Exclusions
{
    /** @return string[] */
    public static function channelIds(PDO $pdo): array
    {
        $ids = Env::list('CRAWL_IGNORE_CHANNEL_IDS');
        $manual = []; // ausdruecklich ausgenommene Channels - diese gewinnen immer

        $categories = [];
        foreach ($pdo->query('SELECT target_id, kind FROM crawl_exclusions') as $r) {
            if ($r['kind'] === 'category') {
                $categories[] = (string) $r['target_id'];
            } else {
                $ids[] = (string) $r['target_id'];
                $manual[] = (string) $r['target_id'];
            }
        }
        $manual = array_merge($manual, Env::list('CRAWL_IGNORE_CHANNEL_IDS'));

        if ($categories !== []) {
            $in = implode(',', array_fill(0, count($categories), '?'));
            $st = $pdo->prepare("SELECT channel_id FROM discord_channels WHERE parent_id IN ($in)");
            $st->execute($categories);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[] = (string) $id;
            }
        }

        foreach ($pdo->query("SELECT channel_id FROM discord_channels WHERE kind = 'channel' AND auto_reason <> ''") as $r) {
            $ids[] = (string) $r['channel_id'];
        }

        // Ticket-Kategorien (nur antworten): alle Channels darin sind keine Wissensquelle ...
        $answerCats = Env::list('ANSWER_ONLY_CATEGORY_IDS');
        if ($answerCats !== []) {
            $in = implode(',', array_fill(0, count($answerCats), '?'));
            $st = $pdo->prepare("SELECT channel_id FROM discord_channels WHERE parent_id IN ($in)");
            $st->execute($answerCats);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[] = (string) $id;
            }
        }

        // ... ausser den ausdruecklich freigegebenen Quell-Channels (ANSWER_SOURCE_CHANNEL_IDS)
        $allowed = array_diff(Env::list('ANSWER_SOURCE_CHANNEL_IDS'), $manual);

        return array_values(array_diff(array_unique($ids), $allowed));
    }

    /**
     * Entfernt alles Wissen, das aus ausgenommenen Channels stammt (Server-Infos und gelernte Antworten).
     * Manuell im Panel angelegte Eintraege bleiben unberuehrt.
     *
     * @return array{info:int,knowledge:int}
     */
    public static function purge(PDO $pdo): array
    {
        $ids = self::channelIds($pdo);
        if ($ids === []) {
            return ['info' => 0, 'knowledge' => 0];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $st = $pdo->prepare("DELETE FROM server_info WHERE channel_id IN ($in)");
        $st->execute($ids);
        $info = $st->rowCount();

        $st = $pdo->prepare("DELETE FROM knowledge WHERE source <> 'panel' AND channel_id IN ($in)");
        $st->execute($ids);
        $knowledge = $st->rowCount();

        return ['info' => $info, 'knowledge' => $knowledge];
    }
}
