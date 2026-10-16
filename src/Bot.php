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
                $this->handleTeamMessage($m);
                return;
            }

            $this->handlePlayerMessage($m);
        } catch (\Throwable $e) {
            echo '[Fehler] ' . $e->getMessage() . "\n";
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

    private function handlePlayerMessage(Message $m): void
    {
        $this->observeChannel($m);

        $parentId = $this->parentOf($m);
        if (!$this->inQuestionChannel($m->channel_id, $parentId)) {
            return;
        }
        $norm = Text::normalize($m->content);
        // Support-Chat und Tickets: auf jede Nachricht antworten; sonst nur auf Fragen
        $always = in_array($m->channel_id, $this->alwaysChannels, true) || $this->isAnswerOnlyCategory($parentId);
        if ($always ? mb_strlen(trim($m->content)) < 10 : !Text::looksLikeQuestion($m->content, $norm)) {
            return;
        }
        // Pro Person und Channel nur eine offene Antwort gleichzeitig
        foreach ($this->pending as $p) {
            if ($p["channel"] === $m->channel_id && $p["user"] === $m->author->id) {
                return;
            }
        }

        if (!$this->settings->bool('ANSWERS_ENABLED', true)) {
            return;
        }

        $delay = max(5, Env::int('ANSWER_DELAY_SECONDS', 90));
        if ($this->testMode()) {
            $delay = min($delay, 10); // im Testmodus schnell antworten
        }
        $timer = $this->discord->getLoop()->addTimer($delay, function () use ($m) {
            $this->answerIfUnanswered($m);
        });
        $this->pending[$m->id] = [
            'channel' => $m->channel_id,
            'user' => $m->author->id,
            'priority' => $always || in_array($m->channel_id, $this->questionChannels, true),
            'text' => $m->content,
            'time' => time(),
            'timer' => $timer,
        ];
    }

    private function answerIfUnanswered(Message $m): void
    {
        if (!isset($this->pending[$m->id])) {
            return; // Teamler war schneller
        }
        $q = $this->pending[$m->id];
        unset($this->pending[$m->id]);

        $hit = $this->knowledge->find($q['text'], Env::float('MIN_CONFIDENCE', 0.55));
        $name = $m->author->username;
        $sie = strtolower(Env::get('ADDRESS_FORM', 'du')) === 'sie';
        $server = Env::get('SERVER_NAME', 'unser Server');

        if ($hit !== null) {
            $reply = $sie
                ? "Hallo {$name}, vielen Dank für Ihre Frage! Das Team hat dazu bereits Folgendes mitgeteilt:\n\n{$hit['answer']}\n\n"
                  . "Sollte noch etwas offen sein, melden Sie sich gerne. Ein Teammitglied schaut bei Bedarf ebenfalls vorbei. – {$server}"
                : "Hallo {$name}, danke für deine Frage! Das Team hat dazu bereits Folgendes mitgeteilt:\n\n{$hit['answer']}\n\n"
                  . "Falls noch etwas offen ist, melde dich gerne. Ein Teammitglied schaut bei Bedarf ebenfalls vorbei. – {$server}";
            $this->knowledge->markUsed($hit['id']);
            $m->reply($this->tag($reply))->then(function (Message $sent) use ($q, $hit) {
                $this->botAnswers[$sent->id] = ['question' => $q['text'], 'knowledgeId' => $hit['id'], 'time' => time()];
                $this->pruneBotAnswers();
            });
            return;
        }

        // Kein gelerntes Q&A: in den durchsuchten Server-Infos (Regeln, FAQ, Ankuendigungen) nachsehen.
        $info = $this->knowledge->findInfo($q['text']);
        if ($info !== null) {
            $snippet = mb_strimwidth($info['content'], 0, 900, '…');
            $reply = $sie
                ? "Hallo {$name}, vielen Dank für Ihre Frage! Dazu habe ich im Channel #{$info['channel']} Folgendes gefunden:\n\n{$snippet}\n\n"
                  . "Falls das Ihre Frage nicht beantwortet, meldet sich gerne ein Teammitglied bei Ihnen. – {$server}"
                : "Hallo {$name}, danke für deine Frage! Dazu habe ich im Channel #{$info['channel']} Folgendes gefunden:\n\n{$snippet}\n\n"
                  . "Falls das deine Frage nicht beantwortet, meldet sich gerne ein Teammitglied bei dir. – {$server}";
            $m->reply($this->tag($reply))->then(function (Message $sent) use ($q) {
                $this->botAnswers[$sent->id] = ['question' => $q['text'], 'knowledgeId' => null, 'time' => time()];
                $this->pruneBotAnswers();
            });
            return;
        }

        if ($this->settings->bool('FALLBACK_ENABLED', true) || !empty($q['priority'])) {
            $reply = $sie
                ? "Hallo {$name}, vielen Dank für Ihre Nachricht! Dazu habe ich leider noch keine Antwort gelernt. Ein Teammitglied wird sich so bald wie möglich bei Ihnen melden. Wir bitten um etwas Geduld."
                : "Hallo {$name}, danke für deine Nachricht! Dazu habe ich leider noch keine Antwort gelernt. Ein Teammitglied meldet sich so bald wie möglich bei dir. Bitte hab noch etwas Geduld.";
            $m->reply($this->tag($reply))->then(function (Message $sent) use ($q) {
                // Wird ein Teamler hierauf antworten, soll er trotzdem als Lernquelle dienen.
                $this->botAnswers[$sent->id] = ['question' => $q['text'], 'knowledgeId' => null, 'time' => time()];
                $this->pruneBotAnswers();
            });
        }
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
