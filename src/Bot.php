<?php
declare(strict_types=1);

namespace App;

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use React\EventLoop\TimerInterface;

final class Bot
{
    private Discord $discord;
    private Database $db;
    private Knowledge $knowledge;
    private Moderator $moderator;
    private Settings $settings;
    private bool $noLearn = false;
    /** @var array<string,int> Zeitpunkt der letzten Team-Benachrichtigung je Person und Channel */
    private array $teamPinged = [];
    /** @var array<string,bool> */
    private array $ticketCache = [];
    /** @var array<string,int> */
    private array $ticketCacheTime = [];
    /** @var string[] */
    private array $excludedIds = [];
    private int $excludedLoaded = 0;

    /** @var string[] */
    private array $teamRoles;
    /** @var string[] */
    private array $questionChannels;
    /** @var string[] Kategorien (z.B. Tickets), in denen der Bot nur antwortet und nichts lernt */
    private array $answerOnlyCategories;
    /** @var string[] Channels, in denen auf jede Nachricht geantwortet wird */
    private array $alwaysChannels;
    /** @var string[] */
    private array $moderationIgnore;

    /** Offene Fragen: messageId => [channel, user, text, time, timer] @var array<string,array<string,mixed>> */
    private array $pending = [];
    /** Bot-Antworten: botMessageId => [question, knowledgeId, time] @var array<string,array<string,mixed>> */
    private array $botAnswers = [];

    public function __construct()
    {
        $this->db = new Database();
        $this->knowledge = new Knowledge($this->db);
        $this->moderator = new Moderator();
        $this->settings = new Settings($this->db);

        $this->teamRoles = Env::list('TEAM_ROLE_IDS');
        $this->questionChannels = Env::list('QUESTION_CHANNEL_IDS');
        $this->answerOnlyCategories = Env::list("ANSWER_ONLY_CATEGORY_IDS");
        $this->alwaysChannels = Env::list("ALWAYS_ANSWER_CHANNEL_IDS");
        $this->moderationIgnore = Env::list('MODERATION_IGNORE_CHANNEL_IDS');

        $this->discord = new Discord([
            'token' => Env::get('DISCORD_TOKEN'),
            'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
            'logger' => Log::make('bot'),
        ]);
    }

    public function run(): void
    {
        $this->discord->on('init', function (Discord $discord) {
            echo "[Bot] Eingeloggt als {$discord->user->username}\n";
            $discord->on(Event::MESSAGE_CREATE, fn(Message $m) => $this->onMessage($m));
            // Web-Panel als Kindprozess starten - so ist alles ein einziger pm2-Prozess
            (new PanelServer($discord->getLoop()))->start();

            // Kategorien/Channels fuer das Panel (Ausschluss-Ansicht) aktuell halten
            $syncChannels = function () use ($discord): string {
                try {
                    $gid = Env::get('GUILD_ID');
                    $guild = $gid !== '' ? $discord->guilds->get('id', $gid) : $discord->guilds->first();
                    if ($guild === null) {
                        return 'Server nicht gefunden (GUILD_ID pruefen)';
                    }
                    ChannelSync::sync($this->db, $guild);
                    return 'Channels synchronisiert';
                } catch (\Throwable $e) {
                    echo '[ChannelSync] ' . $e->getMessage() . "\n";
                    return 'Fehler: ' . $e->getMessage();
                }
            };
            $syncChannels();
            $discord->getLoop()->addPeriodicTimer(300, $syncChannels);

            // Auftraege aus dem Panel, automatische Durchsuchung, Heartbeat
            (new Jobs($this->db, $discord->getLoop(), $this->settings, ['sync_channels' => $syncChannels]))->start();
        });
        $this->discord->run();
    }

    private function onMessage(Message $m): void
    {
        try {
            if ($m->author->bot || !$m->guild_id) {
                return;
            }
            $guild = Env::get('GUILD_ID');
            if ($guild !== '' && $m->guild_id !== $guild) {
                return;
            }

            if ($this->isTeam($m)) {
                $this->trace($m, "Nachricht von einem Teamler - der Bot antwortet Teamlern nur im Testmodus (Panel > Steuerung)");
                if ($this->isTicketChannel($m) && $this->handleTicketTeamMessage($m)) {
                    return;
                }
                $this->handleTeamMessage($m);
                return;
            }

            $this->handlePlayerMessage($m);
        } catch (\Throwable $e) {
            echo '[Fehler] ' . $e->getMessage() . "\n";
        }
    }

    // ------------------------------------------------------------------ Tickets: Bot an/aus pro Ticket

    private function isTicketChannel(Message $m): bool
    {
        return $this->isAnswerOnlyCategory($this->parentOf($m));
    }

    /** Ist der Bot in diesem Ticket stummgeschaltet (weil ein Teamler uebernommen hat)? */
    private function ticketMuted(string $channelId): bool
    {
        if (time() - ($this->ticketCacheTime[$channelId] ?? 0) > 5) {
            $st = $this->db->pdo()->prepare('SELECT muted FROM ticket_state WHERE channel_id = ?');
            $st->execute([$channelId]);
            $this->ticketCache[$channelId] = (bool) $st->fetchColumn();
            $this->ticketCacheTime[$channelId] = time();
        }
        return $this->ticketCache[$channelId];
    }

    private function setTicketMuted(string $channelId, bool $muted, string $by): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ticket_state (channel_id, muted, changed_by, updated_at) VALUES (?,?,?,NOW())
             ON DUPLICATE KEY UPDATE muted = VALUES(muted), changed_by = VALUES(changed_by), updated_at = NOW()'
        )->execute([$channelId, $muted ? 1 : 0, $by]);
        $this->ticketCache[$channelId] = $muted;
        $this->ticketCacheTime[$channelId] = time();
    }

    /**
     * Teamler-Nachricht in einem Ticket: Befehle "!bot an" / "!bot aus", sonst uebernimmt der Teamler
     * und der Bot schweigt in diesem Ticket, bis ein Teamler ihn mit "!bot an" wieder einsetzt.
     *
     * @return bool true, wenn die Nachricht ein Befehl war
     */
    private function handleTicketTeamMessage(Message $m): bool
    {
        if (preg_match('/^\s*!(?:bot|ki)\s*(an|ein|on|aus|off)?\s*$/iu', (string) $m->content, $mm)) {
            $arg = strtolower($mm[1] ?? 'an');
            $on = in_array($arg, ['an', 'ein', 'on'], true);
            $this->setTicketMuted($m->channel_id, !$on, $m->author->id);
            $m->reply($this->tag($on
                ? 'Der Bot ist in diesem Ticket wieder aktiv und beantwortet Fragen.'
                : 'Der Bot ist in diesem Ticket ausgeschaltet. Mit !bot an kann er wieder eingesetzt werden.'));
            $this->trace($m, $on ? 'Teamler hat den Bot in diesem Ticket eingeschaltet' : 'Teamler hat den Bot in diesem Ticket ausgeschaltet');
            return true;
        }

        if (!$this->ticketMuted($m->channel_id)) {
            $this->setTicketMuted($m->channel_id, true, $m->author->id);
            $this->trace($m, 'Teamler hat das Ticket uebernommen - Bot schweigt hier (wieder aktivieren mit !bot an)');
        }
        // laufende Antworten in diesem Ticket abbrechen
        foreach ($this->pending as $id => $p) {
            if ($p['channel'] === $m->channel_id) {
                $this->cancelPending((string) $id);
            }
        }
        return false;
    }

    /** Diagnose im Log: warum antwortet der Bot (nicht)? Nur in Antwort-Channels. Abschaltbar mit ANSWER_DEBUG=false. */
    private function trace(Message $m, string $msg): void
    {
        if (!$this->settings->bool("ANSWER_DEBUG", true)) {
            return;
        }
        $watched = in_array($m->channel_id, $this->questionChannels, true)
            || in_array($m->channel_id, $this->alwaysChannels, true)
            || $this->isAnswerOnlyCategory($this->parentOf($m));
        if ($watched) {
            echo "[Antwort] #{$m->channel_id} {$m->author->username}: {$msg}
";
        }
    }

    private function testMode(): bool
    {
        return $this->settings->bool('TEST_MODE', false);
    }

    /** Im Testmodus kennzeichnen, dass die Nachricht aus dem Test stammt. */
    private function tag(string $text): string
    {
        return $this->testMode() ? "🧪 **Testmodus** – {$text}" : $text;
    }

    /** Lernt nur, wenn das Lernen im Panel aktiviert ist (und nie aus Testnachrichten). */
    private function learn(string $question, string $answer, string $userId, string $channelId): void
    {
        if ($this->noLearn) {
            return;
        }
        if ($this->testMode() || !$this->settings->bool('LEARNING_ENABLED', true)) {
            return;
        }
        // Nichts aus ausgenommenen Channels / Kategorien / Team-Channels lernen (Liste kurz zwischenspeichern)
        if (time() - $this->excludedLoaded > 30) {
            $this->excludedIds = Exclusions::channelIds($this->db->pdo());
            $this->excludedLoaded = time();
        }
        if (in_array($channelId, $this->excludedIds, true)) {
            return;
        }
        $this->knowledge->learn($question, $answer, $userId, $channelId);
    }

    private function isTeam(Message $m): bool
    {
        // Testmodus: Teamler werden wie normale Spieler behandelt (nicht ignoriert).
        if ($this->testMode()) {
            return false;
        }
        $member = $m->member;
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

    // ------------------------------------------------------------------ Lernen

    private function handleTeamMessage(Message $m): void
    {
        $this->noLearn = $this->isAnswerOnlyCategory($this->parentOf($m)); // Tickets: nur antworten, nichts lernen
        $text = trim($m->content);
        if ($text === '') {
            return;
        }
        $channelId = $m->channel_id;
        $ref = $m->referenced_message;

        // 1) Teamler antwortet direkt auf eine Nachricht (Reply)
        if ($ref !== null) {
            // Antwort auf eine Bot-Antwort = Korrektur
            if (isset($this->botAnswers[$ref->id])) {
                $info = $this->botAnswers[$ref->id];
                $this->learn($info['question'], $text, $m->author->id, $channelId);
                if ($info['knowledgeId'] !== null) {
                    $this->knowledge->penalize($info['knowledgeId']);
                }
                unset($this->botAnswers[$ref->id]);
                return;
            }
            if (!$ref->author->bot) {
                $this->cancelPending($ref->id);
                if (Text::looksLikeQuestion($ref->content, Text::normalize($ref->content))) {
                    $this->learn($ref->content, $text, $m->author->id, $channelId);
                }
                return;
            }
        }

        // 2) Teamler schreibt ohne Reply -> juengste offene Frage im Channel
        $window = Env::int('LEARN_WINDOW_SECONDS', 900);
        $latestId = null;
        $latestTime = 0;
        foreach ($this->pending as $id => $q) {
            if ($q['channel'] === $channelId && time() - $q['time'] <= $window && $q['time'] >= $latestTime) {
                $latestId = (string) $id;
                $latestTime = $q['time'];
            }
        }
        if ($latestId !== null) {
            $q = $this->pending[$latestId];
            $this->cancelPending($latestId);
            $this->learn($q['text'], $text, $m->author->id, $channelId);
        }
    }

    // ------------------------------------------------------------------ Antworten

    /** @var array<string,int> Verwarnungen pro Person und Channel (gegen Mehrfach-Hinweise) */
    private array $mentionWarned = [];

    /**
     * Spieler duerfen weder @everyone/@here verwenden noch Teamler (per Name oder Rolle) markieren.
     * Es wird nur ein freundlicher Hinweis gesendet - ohne Strafe.
     */
    private function checkMentions(Message $m): void
    {
        if (!$this->settings->bool('MENTION_PROTECTION_ENABLED', true)) {
            return;
        }
        $content = (string) $m->content;
        if ($content === '') {
            return;
        }

        $teamRoleIds = array_values(array_filter(array_unique(array_merge(
            $this->teamRoles,
            Env::list('TEAM_PING_ROLE_IDS'),
            [Env::get('MOD_ROLE_ID')]
        ))));

        // @everyone / @here und Team-Rollen (direkt aus dem Text, damit Antworten per Reply nicht faelschlich zaehlen)
        $everyone = (bool) preg_match('/@(everyone|here)\b/i', $content);
        preg_match_all('/<@&(\d+)>/', $content, $roleMatches);
        $roleHit = array_intersect($roleMatches[1], $teamRoleIds) !== [];
        if ($everyone || $roleHit) {
            $this->warnMention($m, $everyone);
            return;
        }

        // einzelne Teamler per Name markiert?
        preg_match_all('/<@!?(\d+)>/', $content, $userMatches);
        $ids = array_values(array_diff(array_unique($userMatches[1]), [$m->author->id]));
        if ($ids === [] || $teamRoleIds === []) {
            return;
        }
        $guild = $this->discord->guilds->get('id', $m->guild_id);
        if ($guild === null) {
            return;
        }
        foreach (array_slice($ids, 0, 5) as $id) {
            $guild->members->fetch($id)->then(function ($member) use ($m, $teamRoleIds) {
                foreach ($teamRoleIds as $roleId) {
                    if ($member->roles->has($roleId)) {
                        $this->warnMention($m, false);
                        return;
                    }
                }
            }, function () {
                // Nutzer nicht (mehr) auf dem Server - nichts zu tun
            });
        }
    }

    private function warnMention(Message $m, bool $everyone): void
    {
        $key = $m->author->id . '|' . $m->channel_id;
        if (isset($this->mentionWarned[$key]) && time() - $this->mentionWarned[$key] < 60) {
            return;
        }
        $this->mentionWarned[$key] = time();
        $this->trace($m, 'Teamler/@everyone markiert - Hinweis wird gesendet');
        echo "[Markierung] {$m->author->username} hat " . ($everyone ? '@everyone/@here' : 'Teammitglieder') . " markiert - Hinweis gesendet\n";

        $name = $m->author->username;
        $sie = strtolower(Env::get('ADDRESS_FORM', 'du')) === 'sie';
        $text = $sie
            ? "Hallo {$name}, bitte verwenden Sie kein @everyone oder @here und markieren Sie keine Teammitglieder – weder per Name noch per Rolle. Das ist nicht erlaubt. Das Team sieht Ihre Nachricht auch ohne Markierung und meldet sich so bald wie möglich. Vielen Dank für Ihr Verständnis!"
            : "Hallo {$name}, bitte verwende kein @everyone oder @here und markiere keine Teammitglieder – weder per Name noch per Rolle. Das ist nicht erlaubt. Das Team sieht deine Nachricht auch ohne Markierung und meldet sich so bald wie möglich. Vielen Dank für dein Verständnis!";
        $text = str_replace(['@everyone', '@here'], ['@​everyone', '@​here'], $text);
        $m->reply($this->tag($text));
    }

    private function handlePlayerMessage(Message $m): void
    {
        $this->checkMentions($m);
        $this->observeChannel($m);

        $parentId = $this->parentOf($m);
        if ($this->isAnswerOnlyCategory($parentId) && $this->ticketMuted($m->channel_id)) {
            $this->trace($m, "Ticket wird von einem Teamler betreut - Bot schweigt (wieder aktivieren: Teamler schreibt !bot an)");
            return;
        }
        if (!$this->inQuestionChannel($m->channel_id, $parentId)) {
            $this->trace($m, "Channel gehoert nicht zu den Antwort-Channels (QUESTION_CHANNEL_IDS)");
            return;
        }
        $norm = Text::normalize($m->content);
        // Support-Chat und Tickets: auf jede Nachricht antworten; sonst nur auf Fragen
        $always = in_array($m->channel_id, $this->alwaysChannels, true) || $this->isAnswerOnlyCategory($parentId);
        if ($always ? mb_strlen(trim($m->content)) < 10 : !Text::looksLikeQuestion($m->content, $norm)) {
            $this->trace($m, $always ? "zu kurz (unter 10 Zeichen)" : "keine Frage erkannt (Fragezeichen oder Fragewort wie wie/was/wo noetig)");
            return;
        }
        // Pro Person und Channel nur eine offene Antwort gleichzeitig
        foreach ($this->pending as $p) {
            if ($p["channel"] === $m->channel_id && $p["user"] === $m->author->id) {
                $this->trace($m, "es wartet bereits eine Antwort fuer diese Person");
                return;
            }
        }

        if (!$this->settings->bool('ANSWERS_ENABLED', true)) {
            $this->trace($m, "Fragen beantworten ist im Panel ausgeschaltet (Steuerung)");
            return;
        }
        if (!Text::looksLikeQuestion($m->content, $norm) && $this->isSmallTalk($norm)) {
            $this->trace($m, "Smalltalk/Dank erkannt - keine Antwort noetig");
            return;
        }

        $priority = $always || in_array($m->channel_id, $this->questionChannels, true);
        $q = ['channel' => $m->channel_id, 'user' => $m->author->id, 'priority' => $priority, 'text' => $m->content, 'time' => time()];

        // Gibt es ueberhaupt eine gueltige Antwort? Wenn nicht: sofort ans Team weiterleiten (ohne Wartezeit).
        if ($this->buildAnswer($m->content) === null) {
            $this->trace($m, 'keine gueltige Antwort im Wissen - leite sofort ans Team weiter');
            $this->askTeam($m, $q, $m->author->username, strtolower(Env::get('ADDRESS_FORM', 'du')) === 'sie');
            return;
        }

        $delay = max(5, Env::int('ANSWER_DELAY_SECONDS', 90));
        if ($this->isAnswerOnlyCategory($parentId)) {
            $delay = max(3, Env::int("TICKET_ANSWER_DELAY_SECONDS", 8)); // in Tickets zeitnah antworten
        }
        if ($this->testMode()) {
            $delay = min($delay, 10); // im Testmodus schnell antworten
        }
        $this->trace($m, "Antwort gefunden - sende in {$delay} s, falls kein Teamler zuvorkommt");
        $timer = $this->discord->getLoop()->addTimer($delay, function () use ($m) {
            try {
                $this->answerIfUnanswered($m);
            } catch (\Throwable $e) {
                echo '[Fehler] Antwort fehlgeschlagen: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
            }
        });
        $this->pending[$m->id] = $q + ['timer' => $timer];
    }

    /** Dank, Gruesse und Zustimmung brauchen keine Antwort und keine Team-Weiterleitung. */
    private function isSmallTalk(string $norm): bool
    {
        $words = $norm === '' ? [] : explode(' ', $norm);
        if (count($words) > 7) {
            return false;
        }
        return (bool) preg_match('/\b(danke|dankeschoen|dankesehr|thx|thanks|ty|passt|super|top|perfekt|ok|okay|alles klar|verstanden|hallo|hi|hey|moin|servus|bye|tschuess|ciao|gute nacht|lol|haha|xd)\b/', $norm);
    }

    /**
     * Baut aus dem Wissen eine sinngemaesse Antwort. null = keine gueltige Antwort gefunden.
     *
     * @return array{body:string,kid:?int,hitId:?int}|null
     */
    private function buildAnswer(string $question): ?array
    {
        // 1) gelernte Antwort des Teams (sehr aehnliche Frage), 2) freie Suche ueber alles Gelernte und alle Server-Infos
        $hit = $this->knowledge->find($question, Env::float('MIN_CONFIDENCE', 0.55));
        $context = $this->knowledge->searchMany($question, 4, 0.25);
        if ($hit !== null) {
            $context = array_values(array_filter($context, fn($c) => !($c['type'] === 'qa' && $c['id'] === $hit['id'])));
            array_unshift($context, ['type' => 'qa', 'id' => $hit['id'], 'text' => $hit['answer'], 'channel' => '', 'score' => 1.0]);
        }
        if ($context === []) {
            return null;
        }
        // nur antworten, wenn der Treffer sicher genug ist
        if ($hit === null && (float) $context[0]['score'] < Env::float('SEARCH_MIN_SCORE', 0.6)) {
            return null;
        }
        // und wenn die Antwort die Frage sinngemaess abdeckt
        $body = Answer::compose($this->knowledge, $context, $question);
        if ($body === null) {
            return null;
        }
        return ['body' => $body, 'kid' => $hit['id'] ?? null, 'hitId' => $hit['id'] ?? null];
    }

    private function answerIfUnanswered(Message $m): void
    {
        if (!isset($this->pending[$m->id])) {
            $this->trace($m, "ein Teamler hat bereits geantwortet - Bot bleibt still");
            return; // Teamler war schneller
        }
        $q = $this->pending[$m->id];
        unset($this->pending[$m->id]);
        if ($this->isTicketChannel($m) && $this->ticketMuted($m->channel_id)) {
            return; // Teamler hat inzwischen uebernommen
        }

        $plan = $this->buildAnswer($q['text']);
        if ($plan === null) {
            $this->askTeam($m, $q, $m->author->username, strtolower(Env::get('ADDRESS_FORM', 'du')) === 'sie');
            return;
        }
        if ($plan['hitId'] !== null) {
            $this->knowledge->markUsed($plan['hitId']);
        }
        $this->trace($m, 'sende Antwort');
        // nur die Antwort, ohne Begruessung oder Quellenangabe
        $this->sendAnswer($m, $plan['body'], $q['text'], $plan['kid']);
    }

    /** Der Bot weiss nicht weiter: Team sofort automatisch markieren (pro Person und Channel hoechstens alle 2 Minuten). */
    private function askTeam(Message $m, array $q, string $name, bool $sie): void
    {
        $key = $m->author->id . '|' . $m->channel_id;
        if (isset($this->teamPinged[$key]) && time() - $this->teamPinged[$key] < 120) {
            $this->trace($m, 'Team wurde fuer diese Person bereits benachrichtigt');
            return;
        }
        $this->teamPinged[$key] = time();

        $roles = Env::list('TEAM_PING_ROLE_IDS') ?: array_slice(Env::list('TEAM_ROLE_IDS'), 0, 1);
        $ping = $roles ? ' ' . implode(' ', array_map(fn($id) => "<@&{$id}>", $roles)) : '';
        $text = $sie
            ? "Hallo {$name}, dazu kann ich Ihnen leider keine sichere Antwort geben. Ich habe das Team benachrichtigt, es meldet sich bei Ihnen.{$ping}"
            : "Hallo {$name}, dazu kann ich dir leider keine sichere Antwort geben. Ich habe das Team benachrichtigt, es meldet sich bei dir.{$ping}";
        $this->trace($m, 'Team wird markiert' . ($ping === '' ? ' (TEAM_PING_ROLE_IDS/TEAM_ROLE_IDS nicht gesetzt)' : ''));
        // Frage merken: antwortet ein Teamler per Reply auf diese Nachricht, lernt der Bot daraus
        $this->sendAnswer($m, $text, $q['text'], null, true);
    }

    /** Antwort senden und merken, damit Teamler sie per Reply korrigieren koennen. */
    private function sendAnswer(Message $m, string $text, string $question, ?int $knowledgeId, bool $keepRolePings = false): void
    {
        // keine Massen-Erwaehnungen, egal was in Wissen steht (Team-Markierung nur beim Hinweis an das Team)
        $text = str_replace(['@everyone', '@here'], ['@​everyone', '@​here'], $text);
        $text = preg_replace($keepRolePings ? '/<@!?\d+>/' : '/<@[!&]?\d+>/', '', $text) ?? $text;
        $text = mb_strimwidth($text, 0, 1900, '…');

        $m->reply($this->tag($text))->then(function (Message $sent) use ($question, $knowledgeId) {
            $this->botAnswers[$sent->id] = ['question' => $question, 'knowledgeId' => $knowledgeId, 'time' => time()];
            $this->pruneBotAnswers();
        });
    }

    private function cancelPending(string $messageId): void
    {
        if (!isset($this->pending[$messageId])) {
            return;
        }
        $timer = $this->pending[$messageId]['timer'];
        if ($timer instanceof TimerInterface) {
            $this->discord->getLoop()->cancelTimer($timer);
        }
        unset($this->pending[$messageId]);
    }

    private function pruneBotAnswers(): void
    {
        $cut = time() - 86400;
        $this->botAnswers = array_filter($this->botAnswers, fn($a) => $a['time'] >= $cut);
    }

    private function inQuestionChannel(string $channelId, string $parentId = ""): bool
    {
        if ($this->isAnswerOnlyCategory($parentId)) {
            return true; // Tickets: immer antworten
        }
        return $this->questionChannels === [] || in_array($channelId, $this->questionChannels, true);
    }

    private function isAnswerOnlyCategory(string $parentId): bool
    {
        return $parentId !== "" && in_array($parentId, $this->answerOnlyCategories, true);
    }

    private function parentOf(Message $m): string
    {
        return (string) ($m->channel->parent_id ?? "");
    }

    // ------------------------------------------------------------------ Channel-Überwachung

    private function observeChannel(Message $m): void
    {
        if (!$this->settings->bool('MODERATION_ENABLED', true) || in_array($m->channel_id, $this->moderationIgnore, true)) {
            return;
        }
        $result = $this->moderator->observe($m->channel_id, $m->author->id, $m->content, time());
        if ($result === null) {
            return;
        }

        $sie = strtolower(Env::get('ADDRESS_FORM', 'du')) === 'sie';
        $mentions = implode(' ', array_map(fn($id) => "<@{$id}>", $result['users']));
        $text = $sie
            ? "Hallo zusammen {$mentions}, die Stimmung scheint gerade etwas angespannt zu sein. Wir möchten Sie freundlich bitten, "
              . "respektvoll miteinander umzugehen und sachlich zu bleiben. Vielen Dank für Ihr Verständnis!"
            : "Hallo zusammen {$mentions}, die Stimmung scheint gerade etwas angespannt zu sein. Wir bitten euch freundlich, "
              . "respektvoll miteinander umzugehen und sachlich zu bleiben. Vielen Dank für euer Verständnis!";

        $m->channel->sendMessage($this->tag($text));

        $st = $this->db->pdo()->prepare('INSERT INTO interventions (channel_id, user_ids, heat, created_at) VALUES (?,?,?,?)');
        $st->execute([$m->channel_id, implode(',', $result['users']), $result['heat'], date('Y-m-d H:i:s')]);

        // Wiederholte Eskalation -> Team informieren
        if ($result['repeat']) {
            $logId = Env::get('LOG_CHANNEL_ID');
            if ($logId !== '') {
                $role = Env::get('MOD_ROLE_ID');
                $ping = $role !== '' ? "<@&{$role}> " : '';
                $channel = $this->discord->getChannel($logId);
                $channel?->sendMessage(
                    "{$ping}Eskalation in <#{$m->channel_id}>: wiederholte Spannungen zwischen {$mentions}. Bitte einmal nachschauen."
                );
            }
        }
    }
}
