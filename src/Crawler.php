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
    /** @var string[] Ticket-Kategorien: nur antworten, keine Wissensquelle */
    private array $answerCats = [];
    /** @var string[] ausdruecklich freigegebene Quell-Channels (z.B. Wissens-Ticket) */
    private array $sourceAllow = [];
    /** @var array<string,bool> */
    private array $excludedChannels = [];
    /** @var array<string,bool> */
    private array $excludedCategories = [];

    /** @var array<string,int> */
    private array $stats = ['channels' => 0, 'skipped' => 0, 'messages' => 0, 'pairs' => 0, 'infos' => 0, 'team' => 0, 'empty' => 0];
    private bool $full = false;
    /** @var array<string,bool> */
    private array $unknownMembers = [];

    public function __construct()
    {
        $this->teamRoles = Env::list('TEAM_ROLE_IDS');
        if ($this->teamRoles === []) {
            fwrite(STDERR, "TEAM_ROLE_IDS ist leer - ohne Team-Rollen kann nicht sicher erkannt werden, welche Channels nur fuers Team sind. Abbruch.\n");
            exit(1);
        }
        $this->ignore = Env::list('CRAWL_IGNORE_CHANNEL_IDS');
        $this->answerCats = Env::list('ANSWER_ONLY_CATEGORY_IDS');
        $this->sourceAllow = Env::list('ANSWER_SOURCE_CHANNEL_IDS');
        $this->maxPerChannel = max(100, Env::int('CRAWL_MAX_MESSAGES_PER_CHANNEL', 3000));
        $this->learnWindow = Env::int('LEARN_WINDOW_SECONDS', 900);
        $this->full = in_array('full', $GLOBALS['argv'] ?? [], true);

        $this->db = new Database();
        $this->knowledge = new Knowledge($this->db);
        $this->discord = new Discord([
            'token' => Env::get('DISCORD_TOKEN'),
            'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
            'logger' => Log::make('bot'),
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
            ChannelSync::sync($this->db, $guild);
            $this->loadExclusions();
            echo '  Ausgeschlossen im Panel: ' . count($this->excludedCategories) . ' Kategorien, '
                . count($this->excludedChannels) . " Channels\n";
            echo '  Modus: ' . ($this->full ? 'komplett neu einlesen' : 'nur neue Nachrichten') . "\n";

            // Wissen aus inzwischen ausgenommenen oder Team-Channels entfernen
            $purged = Exclusions::purge($this->db->pdo());
            if ($purged['info'] + $purged['knowledge'] > 0) {
                echo "  Entfernt (aus ausgenommenen/Team-Channels): {$purged['knowledge']} Antworten, {$purged['info']} Infos\n";
            }

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
                    "\nFertig: %d Channels gelesen, %d uebersprungen, %d Nachrichten ausgewertet (%d vom Team).\n"
                    . "        Gelernt: %d Frage/Antwort-Paare, %d Infos gespeichert.\n",
                    $this->stats['channels'], $this->stats['skipped'], $this->stats['messages'], $this->stats['team'],
                    $this->stats['pairs'], $this->stats['infos']
                );
                if ($this->stats['messages'] > 0 && $this->stats['team'] === 0) {
                    echo "        ACHTUNG: Es wurde kein Teamler erkannt. TEAM_ROLE_IDS pruefen (Rollen-IDs, nicht Namen).\n";
                }
                if ($this->stats['messages'] > 0 && $this->stats['empty'] >= $this->stats['messages']) {
                    echo "        ACHTUNG: Alle Nachrichten waren ohne Text. Message Content Intent im Developer Portal aktivieren!\n";
                }
                if ($this->stats['messages'] === 0) {
                    echo "        Es gab keine neuen Nachrichten. Fuer ein komplettes Neu-Einlesen: php crawl.php full (bzw. im Panel 'Komplett neu einlesen').\n";
                }
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
        // Ausdruecklich freigegebener Quell-Channel (z.B. Wissens-Ticket): immer lesen
        if (in_array($channel->id, $this->sourceAllow, true)) {
            return null;
        }
        // Ticket-Kategorie: der Bot antwortet dort nur, liest sie aber nicht
        if ($channel->parent_id && in_array($channel->parent_id, $this->answerCats, true)) {
            return 'Ticket-Kategorie: nur Antworten, keine Wissensquelle';
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

        $stopAt = null;
        if (!$this->full) {
            $st = $this->db->pdo()->prepare('SELECT newest_message_id FROM crawl_channels WHERE channel_id = ?');
            $st->execute([$channel->id]);
            $stopAt = $st->fetchColumn() ?: null;
        }

        echo "  [lese]          #{$name}" . ($stopAt ? ' (nur neue Nachrichten)' : '') . " ...\n";

        return $this->fetchHistory($channel, $stopAt)->then(
            function (array $messages) use ($guild, $channel, $stopAt) {
                $this->stats['channels']++;
                $this->stats['messages'] += count($messages);

                $authors = [];
                foreach ($messages as $m) {
                    if ($m->author && !$m->author->bot) {
                        $authors[$m->author->id] = true;
                    }
                }
                return $this->ensureMembers($guild, array_keys($authors))->then(function () use ($guild, $channel, $messages, $stopAt) {
                    $r = $this->evaluate($guild, $channel, $messages);
                    foreach (['pairs', 'infos'] as $k) {
                        $this->stats[$k] += $r[$k];
                    }
                    $this->stats['team'] += $r['team'];
                    $this->stats['empty'] += $r['empty'];

                    // Stand nur vorruecken, wenn die Nachrichten auch lesbar waren (sonst beim naechsten Mal erneut versuchen)
                    $newest = $stopAt;
                    if ($r['total'] === 0 || $r['empty'] < $r['total']) {
                        foreach ($messages as $m) {
                            if ($newest === null || (int) $m->id > (int) $newest) {
                                $newest = $m->id;
                            }
                        }
                    }
                    $this->saveChannelState($channel, 'read', '', $newest, $r['total']);

                    echo sprintf(
                        "                  %d Nachrichten | %d vom Team | %d Fragen | %d Antworten gelernt | %d Infos gespeichert\n",
                        $r['total'], $r['team'], $r['questions'], $r['pairs'], $r['infos']
                    );
                    if ($r['total'] >= 20 && $r['empty'] === $r['total']) {
                        echo "                  ACHTUNG: Alle Nachrichten ohne Text. Message Content Intent im Developer Portal aktivieren!\n";
                    } elseif ($r['total'] >= 20 && $r['team'] === 0) {
                        echo "                  Hinweis: Kein Teamler erkannt - TEAM_ROLE_IDS pruefen.\n";
                    }
                });
            }
        )->then(null, function ($e) use ($channel, $name) {
            $msg = is_object($e) && method_exists($e, 'getMessage') ? $e->getMessage() : (string) $e;
            if ($e instanceof \Throwable) {
                $msg .= ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
            echo "  [Fehler]        #{$name}: {$msg}\n";
            $this->stats['skipped']++;
            $this->saveChannelState($channel, 'error', mb_substr($msg, 0, 250), null, 0);
        });
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

    /**
     * @param Message[] $messages
     * @return array<string,int>
     */
    private function evaluate(Guild $guild, Channel $channel, array $messages): array
    {
        $r = ['total' => count($messages), 'empty' => 0, 'team' => 0, 'questions' => 0, 'pairs' => 0, 'infos' => 0];
        usort($messages, fn($a, $b) => (int) $a->id <=> (int) $b->id);

        $openQuestions = []; // messageId => ['text'=>..., 'time'=>...]

        // Freigegebenes Wissens-Ticket: ALLE Nachrichten (auch Bot-Nachrichten/Embeds) sind Informationsquelle
        $isSource = in_array($channel->id, $this->sourceAllow, true);

        foreach ($messages as $m) {
            try {
                $this->evaluateMessage($guild, $channel, $m, $isSource, $openQuestions, $r);
            } catch (\Throwable $e) {
                // Eine fehlerhafte Nachricht soll nicht den ganzen Channel abbrechen
                echo '  [Fehler] Nachricht ' . $m->id . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
            }
        }
        return $r;
    }

    /**
     * @param array<string,array{text:string,time:int}> $openQuestions
     * @param array<string,int> $r
     */
    private function evaluateMessage(Guild $guild, Channel $channel, Message $m, bool $isSource, array &$openQuestions, array &$r): void
    {
        {
            if ($m->author === null || ($m->author->bot && !$isSource)) {
                return;
            }
            $text = $this->clean((string) $m->content);
            if ($isSource) {
                $text = trim($text . "\n" . $this->embedText($m));
            }
            if ($text === '') {
                $r['empty']++;
                return;
            }
            $time = $m->timestamp ? $m->timestamp->getTimestamp() : time();

            $infoStored = false;
            if ($isSource && mb_strlen($text) >= 20) {
                $r['infos'] += $this->storeInfo($m, $channel, $text);
                $infoStored = true;
            }
            if ($m->author->bot) {
                return;
            }

            if (!$this->isTeam($guild, $m->author->id)) {
                if (Text::looksLikeQuestion($text, Text::normalize($text))) {
                    $openQuestions[$m->id] = ['text' => $text, 'time' => $time];
                    $r['questions']++;
                }
                return;
            }
            $r['team']++;

            // Teamler-Nachricht: Antwort auf eine Spielernachricht?
            $answered = false;
            $ref = $m->referenced_message;
            if ($ref instanceof Message) {
                // Direkte Antwort (Reply) auf eine Nachricht eines Spielers = Frage/Antwort-Paar
                if ($ref->author && !$ref->author->bot && !$this->isTeam($guild, $ref->author->id)) {
                    $q = $this->clean((string) $ref->content);
                    if (mb_strlen($q) >= 6) {
                        $this->knowledge->learn($q, $text, $m->author->id, $channel->id, 'crawl');
                        unset($openQuestions[$ref->id]);
                        $answered = true;
                        $r['pairs']++;
                    }
                }
            } elseif ($m->message_reference === null) {
                // Kein Reply: juengste offene Frage im Zeitfenster
                $bestId = null;
                foreach ($openQuestions as $id => $q) {
                    if ($time - $q['time'] <= $this->learnWindow && $time >= $q['time']) {
                        $bestId = $id;
                    }
                }
                if ($bestId !== null) {
                    $this->knowledge->learn($openQuestions[$bestId]['text'], $text, $m->author->id, $channel->id, 'crawl');
                    unset($openQuestions[$bestId]);
                    $answered = true;
                    $r['pairs']++;
                }
            }

            // Lange Team-Texte ohne Frage-Bezug = Info (Regeln, Ankuendigungen, FAQ)
            if (!$infoStored && !$answered && $ref === null && mb_strlen($text) >= 40) {
                $r['infos'] += $this->storeInfo($m, $channel, $text);
            }
        }
    }

    /** Speichert einen Text in Abschnitten (max. ~700 Zeichen), damit der Bot gezielt den passenden Teil ausgeben kann. */
    private function storeInfo(Message $m, Channel $channel, string $text): int
    {
        $created = $m->timestamp ? $m->timestamp->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
        $n = 0;
        foreach ($this->chunks($text, 700) as $i => $chunk) {
            $this->knowledge->addInfo($i === 0 ? $m->id : $m->id . '-' . $i, $channel->id, (string) $channel->name, $chunk, $created);
            $n++;
        }
        return $n;
    }

    /** @return string[] */
    private function chunks(string $text, int $max): array
    {
        $out = [];
        $cur = '';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            foreach (explode("\n", wordwrap($line, $max, "\n", true)) as $part) {
                if ($cur !== '' && mb_strlen($cur) + mb_strlen($part) + 1 > $max) {
                    $out[] = $cur;
                    $cur = '';
                }
                $cur .= ($cur === '' ? '' : "\n") . $part;
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
        return $out;
    }

    /** Text aus Embeds (z.B. von Ticket-Bots): Titel, Beschreibung und Felder. */
    private function embedText(Message $m): string
    {
        $parts = [];
        try {
            // Embeds in ein einfaches Array umwandeln - unabhaengig davon, ob die Bibliothek Objekte oder Arrays liefert
            // Rohdaten der Nachricht verwenden: die Embed-Objekte der Bibliothek werfen bei manchen Embeds einen Fehler
            $raw = method_exists($m, 'getRawAttributes') ? $m->getRawAttributes() : [];
            $embeds = json_decode((string) json_encode($raw['embeds'] ?? []), true);
            foreach (is_array($embeds) ? $embeds : [] as $e) {
                if (!is_array($e)) {
                    continue;
                }
                foreach ([$e['title'] ?? '', $e['description'] ?? ''] as $t) {
                    if (is_string($t) && trim($t) !== '') {
                        $parts[] = $this->clean($t);
                    }
                }
                foreach (is_array($e['fields'] ?? null) ? $e['fields'] : [] as $f) {
                    if (!is_array($f)) {
                        continue;
                    }
                    $line = trim((string) ($f['name'] ?? '') . ': ' . (string) ($f['value'] ?? ''), ': ');
                    if ($line !== '') {
                        $parts[] = $this->clean($line);
                    }
                }
            }
        } catch (\Throwable $e) {
            echo '  [Hinweis] Embed konnte nicht gelesen werden: ' . $e->getMessage() . "\n";
        }
        return implode("\n", $parts);
    }

    /** Laedt Mitglieder, die noch nicht im Cache sind, einzeln per API - noetig, um Teamler sicher zu erkennen. */
    private function ensureMembers(Guild $guild, array $ids): PromiseInterface
    {
        $chain = resolve(null);
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($this->unknownMembers[$id]) || $guild->members->get('id', $id) !== null) {
                continue;
            }
            $chain = $chain->then(function () use ($guild, $id) {
                return $guild->members->fetch($id)->then(null, function () use ($id) {
                    $this->unknownMembers[$id] = true; // z.B. Server verlassen
                });
            });
        }
        return $chain;
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
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;          // Zeilenumbrueche bleiben erhalten
        $s = preg_replace('/\s*\n\s*/', "\n", $s) ?? $s;
        return trim($s);
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
