<?php
declare(strict_types=1);

/**
 * Durchsucht den Discord-Server und speichert Wissen:  php crawl.php
 * Channels, die nur fuer das Team sichtbar sind, werden nicht gelesen.
 */

if (!is_file(__DIR__ . '/vendor/autoload.php') || !is_file(__DIR__ . '/.env')) {
    fwrite(STDERR, "Bot ist noch nicht eingerichtet. Bitte zuerst ausfuehren: php install.php\n");
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

use App\Crawler;
use App\Env;

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

foreach (['DISCORD_TOKEN', 'DB_NAME', 'DB_USER'] as $required) {
    if (Env::get($required) === '') {
        fwrite(STDERR, "Fehlende Einstellung in .env: {$required}\n");
        exit(1);
    }
}

echo "=== Server-Durchsuchung ===\n";
(new Crawler())->run();
