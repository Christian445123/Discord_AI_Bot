<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::int('DB_PORT', 3306),
            Env::get('DB_NAME')
        );
        $this->pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS knowledge (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question TEXT NOT NULL,
            normalized TEXT NOT NULL,
            answer TEXT NOT NULL,
            answered_by VARCHAR(32) NOT NULL,
            channel_id VARCHAR(32) NOT NULL,
            uses INT UNSIGNED NOT NULL DEFAULT 0,
            score INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS interventions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel_id VARCHAR(32) NOT NULL,
            user_ids TEXT NOT NULL,
            heat INT NOT NULL,
            created_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');
    }
}
