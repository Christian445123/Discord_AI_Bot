<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/vendor/autoload.php') || !is_file(__DIR__ . '/.env')) {
    fwrite(STDERR, "Bot ist noch nicht eingerichtet. Bitte zuerst ausfuehren: php install.php\n");
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

use App\Bot;
use App\Env;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

foreach (['DISCORD_TOKEN', 'DB_NAME', 'DB_USER'] as $required) {
    if (Env::get($required) === '') {
        fwrite(STDERR, "Fehlende Einstellung in .env: {$required}\n");
        exit(1);
    }
}

(new Bot())->run();
