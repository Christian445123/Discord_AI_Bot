<?php
declare(strict_types=1);

namespace App;

use Discord\Parts\Guild\Guild;

/** Schreibt die Kategorien/Channels des Servers in die Datenbank, damit das Panel sie anzeigen kann. */
final class ChannelSync
{
    private const TYPE_TEXT = 0;
    private const TYPE_CATEGORY = 4;
    private const TYPE_ANNOUNCEMENT = 5;

    public static function sync(Database $db, Guild $guild): void
    {
        $pdo = $db->pdo();
        $teamRoles = Env::list('TEAM_ROLE_IDS');

        $names = [];
        foreach ($guild->channels as $c) {
            $names[$c->id] = (string) $c->name;
        }

        $st = $pdo->prepare(
            'INSERT INTO discord_channels (channel_id, name, kind, parent_id, parent_name, position, auto_reason, synced_at)
             VALUES (?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind), parent_id = VALUES(parent_id),
                parent_name = VALUES(parent_name), position = VALUES(position), auto_reason = VALUES(auto_reason), synced_at = NOW()'
        );

        $seen = [];
        foreach ($guild->channels as $c) {
            $type = (int) $c->type;
            if (!in_array($type, [self::TYPE_TEXT, self::TYPE_ANNOUNCEMENT, self::TYPE_CATEGORY], true)) {
                continue;
            }
            $isCategory = $type === self::TYPE_CATEGORY;
            $reason = '';
            if (!$isCategory && $teamRoles !== []) {
                $reason = ChannelPolicy::autoReason($guild, $c, $teamRoles) ?? '';
            }
            $parent = $c->parent_id ?: null;
            $st->execute([
                $c->id,
                (string) $c->name,
                $isCategory ? 'category' : 'channel',
                $parent,
                $parent ? ($names[$parent] ?? '') : '',
                (int) $c->position,
                $reason,
            ]);
            $seen[] = $c->id;
        }

        // Geloeschte Channels entfernen
        if ($seen !== []) {
            $in = implode(',', array_fill(0, count($seen), '?'));
            $pdo->prepare("DELETE FROM discord_channels WHERE channel_id NOT IN ($in)")->execute($seen);
        }
    }
}
