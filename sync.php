<?php
declare(strict_types=1);

/** Aktualisiert die Kategorie-/Channel-Liste fuer das Panel:  php sync.php */

if (!is_file(__DIR__ . '/vendor/autoload.php') || !is_file(__DIR__ . '/.env')) {
    fwrite(STDERR, "Nicht eingerichtet. Bitte zuerst: php install.php\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';

use App\ChannelSync;
use App\Database;
use App\Env;
use Discord\Discord;
use Discord\WebSockets\Intents;

App\Env::load(__DIR__);

$db = new Database();
$discord = new Discord([
    'token' => Env::get('DISCORD_TOKEN'),
    'intents' => Intents::getDefaultIntents(),
]);

$exit = 0;
$discord->on('init', function (Discord $d) use ($db, &$exit) {
    $gid = Env::get('GUILD_ID');
    $guild = $gid !== '' ? $d->guilds->get('id', $gid) : $d->guilds->first();
    if ($guild === null) {
        echo "Server nicht gefunden (GUILD_ID pruefen, ist der Bot auf dem Server?).\n";
        $exit = 1;
    } else {
        ChannelSync::sync($db, $guild);
        echo "Channels synchronisiert.\n";
    }
    $d->close();
});
$discord->run();
exit($exit);
