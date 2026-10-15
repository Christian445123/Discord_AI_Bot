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

    /** @var string[] */
    private array $teamRoles;
    /** @var string[] */
    private array $questionChannels;
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

        $this->teamRoles = Env::list('TEAM_ROLE_IDS');
        $this->questionChannels = Env::list('QUESTION_CHANNEL_IDS');
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

    private function isTeam(Message $m): bool
    {
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
                $this->knowledge->learn($info['question'], $text, $m->author->id, $channelId);
                if ($info['knowledgeId'] !== null) {
                    $this->knowledge->penalize($info['knowledgeId']);
                }
                unset($this->botAnswers[$ref->id]);
                return;
            }
            if (!$ref->author->bot) {
                $this->cancelPending($ref->id);
                if (Text::looksLikeQuestion($ref->content, Text::normalize($ref->content))) {
                    $this->knowledge->learn($ref->content, $text, $m->author->id, $channelId);
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
            $this->knowledge->learn($q['text'], $text, $m->author->id, $channelId);
        }
    }

    // ------------------------------------------------------------------ Antworten

    private function handlePlayerMessage(Message $m): void
    {
        $this->observeChannel($m);

        if (!$this->inQuestionChannel($m->channel_id)) {
            return;
        }
        $norm = Text::normalize($m->content);
        if (!Text::looksLikeQuestion($m->content, $norm)) {
            return;
        }

        $delay = max(5, Env::int('ANSWER_DELAY_SECONDS', 90));
        $timer = $this->discord->getLoop()->addTimer($delay, function () use ($m) {
            $this->answerIfUnanswered($m);
        });
        $this->pending[$m->id] = [
            'channel' => $m->channel_id,
            'user' => $m->author->id,
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
            $m->reply($reply)->then(function (Message $sent) use ($q, $hit) {
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
            $m->reply($reply)->then(function (Message $sent) use ($q) {
                $this->botAnswers[$sent->id] = ['question' => $q['text'], 'knowledgeId' => null, 'time' => time()];
                $this->pruneBotAnswers();
            });
            return;
        }

        if (Env::bool('FALLBACK_ENABLED', true)) {
            $reply = $sie
                ? "Hallo {$name}, vielen Dank für Ihre Nachricht! Dazu habe ich leider noch keine Antwort gelernt. Ein Teammitglied wird sich so bald wie möglich bei Ihnen melden. Wir bitten um etwas Geduld."
                : "Hallo {$name}, danke für deine Nachricht! Dazu habe ich leider noch keine Antwort gelernt. Ein Teammitglied meldet sich so bald wie möglich bei dir. Bitte hab noch etwas Geduld.";
            $m->reply($reply)->then(function (Message $sent) use ($q) {
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

    private function inQuestionChannel(string $channelId): bool
    {
        return $this->questionChannels === [] || in_array($channelId, $this->questionChannels, true);
    }

    // ------------------------------------------------------------------ Channel-Überwachung

    private function observeChannel(Message $m): void
    {
        if (!Env::bool('MODERATION_ENABLED', true) || in_array($m->channel_id, $this->moderationIgnore, true)) {
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

        $m->channel->sendMessage($text);

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
