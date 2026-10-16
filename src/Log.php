<?php
declare(strict_types=1);

namespace App;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/** Logger fuer DiscordPHP. Standard: nur Fehler (LOG_LEVEL=debug|info|warning|error in der .env). */
final class Log
{
    public static function make(string $name = 'bot'): Logger
    {
        $name2 = ucfirst(strtolower(Env::get('LOG_LEVEL', 'error')));
        try {
            $level = class_exists(\Monolog\Level::class)
                ? \Monolog\Level::fromName($name2)
                : Logger::toMonologLevel(strtoupper($name2));
        } catch (\Throwable $e) {
            $level = class_exists(\Monolog\Level::class) ? \Monolog\Level::Error : Logger::ERROR;
        }
        $logger = new Logger($name);
        $logger->pushHandler(new StreamHandler('php://stdout', $level));
        return $logger;
    }
}
