<?php
declare(strict_types=1);

namespace App;

use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Guild;
use Discord\WebSockets\Intents;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Liest die Nachrichtenverlaeufe des Servers und speichert daraus
 *  - Frage/Antwort-Paare (Teamler antwortet auf eine Spielerfrage) und
 *  - Info-Texte des Teams (Regeln, Ankuendigungen, FAQ).
 *
 * Channels, die nur fuer das Team sichtbar sind (oder fuer niemanden ausser Admins), werden NICHT gelesen.
 */
final class Crawler
{
    private const TYPE_TEXT = 0;
    private const TYPE_ANNOUNCEMENT = 5;

    private Discord $discord;
    private Database $db;
    private Knowledge $knowledge;

    /** @var string[] */
    private array $teamRoles;
    /** @var string[] */
    private array $ignore;
    private int $maxPerChannel;
    private int $learnWindow;

    /** @var array{channels:int,skipped:int,messages:int} */
    private array $stats = ['channels' => 0, 'skipped' => 0, 'messages' => 0];

    public function __construct()
    {
        $this->teamRoles = Env::list('TEAM_ROLE_IDS');
        if ($this->teamRoles === []) {
            fwrite(STDERR, "TEAM_ROLE_IDS ist leer - ohne Team-Rollen kann nicht sicher erkannt werden, welche Channels nur fuers Team sind. Abbruch.\n");
            exit(1);
        }
        $this->ignore = Env::list('CRAWL_IGNORE_CHANNEL_IDS');
        $this->maxPerChannel = max(100, Env::int('CRAWL_MAX_MESSAGES_PER_CHANNEL', 3000));
        $this->learnWindow = Env::int('LEARN_WINDOW_SECONDS', 900);

        $this->db = new Database();
        $this->knowledge = new Knowledge($this->db);
        $this->discord = new Discord([
            'token' => Env::get('DISCORD_TOKEN'),
            'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT | Intents::GUILD_MEMBERS,
            'loadAllMembers' => true,
        ]);
    }

    public function run(): void
    {
        $this->discord->on('init', function (Discord $discord) {
            $guildId = Env::get('GUILD_ID');
            $guild = $guildId !== '' ? $discord->guilds->get('id', $guildId) : $discord->guilds->first();
            if (!$guild instanceof Guild) {
                echo "Server nicht gefunden (GUILD_ID pruefen, Bot muss auf dem Server sein).\n";
                $discord->close();
                return;
            }
            echo "Durchsuche Server: {$guild->name}\n";

            $queue = [];
            foreach ($guild->channels as $channel) {
                if (in_array((int) $channel->type, [self::TYPE_TEXT, self::TYPE_ANNOUNCEMENT], true)) {
                    $queue[] = $channel;
                }
            }

            $chain = resolve(null);
            foreach ($queue as $channel) {
                $chain = $chain->then(fn() => $this->processChannel($guild, $channel));
            }
            $chain->then(function () use ($discord) {
                echo sprintf(
                    "\nFertig: %d Channels gelesen, %d uebersprungen, %d Nachrichten ausgewertet.\n",
                    $this->stats['channels'], $this->stats['skipped'], $this->stats['messages']
                );
                $discord->close();
            }, function ($e) use ($discord) {
                echo 'Abbruch: ' . (is_object($e) && method_exists($e, 'getMessage') ? $e->getMessage() : (string) $e) . "\n";
                $discord->close();
            });
        });
        $this->discord->run();
    }

    // ------------------------------------------------------------ Berechtigungen

    /** Liefert null, wenn der Channel gelesen werden darf, sonst den Grund fuers Ueberspringen. */
    private function skipReason(Guild $guild, Channel $channel): ?string
    {
        if (in_array($channel->id, $this->ignore, true)) {
            return 'manuell ausgeschlossen (CRAWL_IGNORE_CHANNEL_IDS)';
        }
        if (isset($this->excludedChannels[$channel->id])) {
            return 'im Panel ausgeschlossen';
        }
        if ($channel->parent_id && isset($this->excludedCategories[$channel->parent_id])) {
            return 'Kategorie im Panel ausgeschlossen';
        }
        return ChannelPolicy::autoReason($guild, $channel, $this->teamRoles);
    }

    /** Laedt die im Panel angeklickten Ausschluesse. */
    private function loadExclusions(): void
    {
        $this->excludedChannels = [];
        $this->excludedCategories = [];
        foreach ($this->db->pdo()->query('SELECT target_id, kind FROM crawl_exclusions') as $row) {
            if ($row['kind'] === 'category') {
                $this->excludedCategories[$row['target_id']] = true;
            } else {
                $this->excludedChannels[$row['target_id']] = true;
            }
        }
    }

    // ------------------------------------------------------------ Channel verarbeiten

    private function processChannel(Guild $guild, Channel $channel): PromiseInterface
    {
        $name = (string) $channel->name;
        $reason = $this->skipReason($guild, $channel);
        if ($reason !== null) {
            echo "  [uebersprungen] #{$name}: {$reason}\n";
            $this->stats['skipped']++;
            $this->saveChannelState($channel, 'skipped', $reason, null, 0);
            return resolve(null);
        }

        $st = $this->db->pdo()->prepare('SELECT newest_message_id FROM crawl_channels WHERE channel_id = ?');
        $st->execute([$channel->id]);
        $stopAt = $st->fetchColumn() ?: null;

        echo "  [lese]          #{$name}" . ($stopAt ? ' (nur neue Nachrichten)' : '') . " ...\n";

        return $this->fetchHistory($channel, $stopAt)->then(
            function (array $messages) use ($guild, $channel, $name, $stopAt) {
                $this->stats['channels']++;
                $this->stats['messages'] += count($messages);
                $newest = $stopAt;
                foreach ($messages as $m) {
                    if ($newest === null || (int) $m->id > (int) $newest) {
                        $newest = $m->id;
                    }
                }
                $this->evaluate($guild, $channel, $messages);
                $this->saveChannelState($channel, 'read', '', $newest, count($messages));
                echo '                  ' . count($messages) . " Nachrichten ausgewertet\n";
            },
            function ($e) use ($channel, $name) {
                $msg = is_object($e) && method_exists($e, 'getMessage') ? $e->getMessage() : (string) $e;
                echo "  [Fehler]        #{$name}: {$msg}\n";
                $this->stats['skipped']++;
                $this->saveChannelState($channel, 'error', mb_substr($msg, 0, 250), null, 0);
            }
        );
    }

    /** @return PromiseInterface<Message[]> */
    private function fetchHistory(Channel $channel, ?string $stopAt): PromiseInterface
    {
        $collected = [];
        $page = null;
        $page = function (?string $before) use ($channel, $stopAt, &$collected, &$page): PromiseInterface {
            $opts = ['limit' => 100];
            if ($before !== null) {
                $opts['before'] = $before;
            }
            return $channel->getMessageHistory($opts)->then(function ($messages) use ($stopAt, &$collected, &$page) {
                $count = 0;
                $oldest = null;
                $reachedKnown = false;
                foreach ($messages as $m) {
                    $count++;
                    if ($oldest === null || (int) $m->id < (int) $oldest) {
                        $oldest = $m->id;
                    }
                    if ($stopAt !== null && (int) $m->id <= (int) $stopAt) {
                        $reachedKnown = true;
                        continue;
                    }
                    $collected[] = $m;
                }
                if ($count < 100 || $reachedKnown || count($collected) >= $this->maxPerChannel || $oldest === null) {
                    return resolve($collected);
                }
                return $page($oldest);
            });
        };
        return $page(null);
    }

    // ------------------------------------------------------------ Auswertung

    /** @param Message[] $messages */
    private function evaluate(Guild $guild, Channel $channel, array $messages): void
    {
        usort($messages, fn($a, $b) => (int) $a->id <=> (int) $b->id);

        $openQuestions = []; // messageId => ['text'=>..., 'time'=>...]
        $usedAsAnswer = [];

        foreach ($messages as $m) {
            if ($m->author === null || $m->author->bot) {
                continue;
            }
            $text = $this->clean((string) $m->content);
            if ($text === '') {
                continue;
            }
            $time = $m->timestamp ? $m->timestamp->getTimestamp() : time();

            if (!$this->isTeam($guild, $m->author->id)) {
                if (Text::looksLikeQuestion($text, Text::normalize($text))) {
                    $openQuestions[$m->id] = ['text' => $text, 'time' => $time];
                }
                continue;
            }

            // Teamler-Nachricht: Antwort auf eine Frage?
            $answered = false;
            $ref = $m->referenced_message;
            if ($ref instanceof Message && $ref->author && !$ref->author->bot && !$this->isTeam($guild, $ref->author->id)) {
                $q = $this->clean((string) $ref->content);
                if ($q !== '' && Text::looksLikeQuestion($q, Text::normalize($q))) {
                    $this->knowledge->learn($q, $text, $m->author->id, $channel->id);
                    unset($openQuestions[$ref->id]);
                    $answered = true;
                }
            } elseif ($ref === null && $m->message_reference === null) {
                // Kein Reply: juengste offene Frage im Zeitfenster
                $bestId = null;
                foreach ($openQuestions as $id => $q) {
                    if ($time - $q['time'] <= $this->learnWindow && $time >= $q['time']) {
                        $bestId = $id;
                    }
                }
                if ($bestId !== null) {
                    $this->knowledge->learn($openQuestions[$bestId]['text'], $text, $m->author->id, $channel->id);
                    unset($openQuestions[$bestId]);
                    $answered = true;
                }
            }

            // Lange Team-Texte ohne Frage-Bezug = Info (Regeln, Ankuendigungen, FAQ)
            if (!$answered && $ref === null && mb_strlen($text) >= 40) {
                $created = $m->timestamp ? $m->timestamp->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
                $this->knowledge->addInfo($m->id, $channel->id, (string) $channel->name, $text, $created);
            }
        }
    }

    private function isTeam(Guild $guild, string $userId): bool
    {
        $member = $guild->members->get('id', $userId);
        if ($member === null) {
            return false;
        }
        foreach ($this->teamRoles as $roleId) {
            if ($member->roles->has($roleId)) {
                return true;
            }
        }
        return false;
    }

    /** Entfernt Personen-Erwaehnungen, damit keine User-IDs in Wissen/Infos landen. */
    private function clean(string $s): string
    {
        $s = preg_replace('/<@[!&]?\d+>/', '', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    private function saveChannelState(Channel $channel, string $status, string $reason, ?string $newest, int $read): void
    {
        $st = $this->db->pdo()->prepare(
            'INSERT INTO crawl_channels (channel_id, channel_name, status, reason, newest_message_id, messages_read, updated_at)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE channel_name = VALUES(channel_name), status = VALUES(status), reason = VALUES(reason),
                newest_message_id = COALESCE(VALUES(newest_message_id), newest_message_id),
                messages_read = messages_read + VALUES(messages_read), updated_at = VALUES(updated_at)'
        );
        $st->execute([$channel->id, (string) $channel->name, $status, $reason, $newest, $read, date('Y-m-d H:i:s')]);
    }
}
