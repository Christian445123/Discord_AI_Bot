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

        // Herkunft eines Eintrags: live (im Chat gelernt), crawl (aus der Server-Durchsuchung), panel (manuell)
        try {
            $this->pdo->exec("ALTER TABLE knowledge ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'live'");
        } catch (\PDOException $e) {
            // Spalte existiert bereits
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS server_info (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(32) NOT NULL UNIQUE,
            channel_id VARCHAR(32) NOT NULL,
            channel_name VARCHAR(100) NOT NULL,
            content TEXT NOT NULL,
            normalized TEXT NOT NULL,
            created_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS crawl_channels (
            channel_id VARCHAR(32) PRIMARY KEY,
            channel_name VARCHAR(100) NOT NULL,
            status VARCHAR(16) NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT \'\',
            newest_message_id VARCHAR(32) NULL,
            messages_read INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS discord_channels (
            channel_id VARCHAR(32) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            parent_id VARCHAR(32) NULL,
            parent_name VARCHAR(100) NOT NULL DEFAULT \'\',
            position INT NOT NULL DEFAULT 0,
            auto_reason VARCHAR(255) NOT NULL DEFAULT \'\',
            synced_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS crawl_exclusions (
            target_id VARCHAR(32) PRIMARY KEY,
            kind VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) PRIMARY KEY,
            v TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ticket_state (
            channel_id VARCHAR(32) PRIMARY KEY,
            muted TINYINT(1) NOT NULL DEFAULT 0,
            changed_by VARCHAR(32) NOT NULL DEFAULT \'\',
            updated_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS jobs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'pending\',
            note VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL
        ) CHARACTER SET utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS bot_status (
            k VARCHAR(32) PRIMARY KEY,
            v VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');

        // aeltere Installationen: v war VARCHAR(255), das reicht fuer die Update-Infos nicht
        try {
            $this->pdo->exec('ALTER TABLE bot_status MODIFY v TEXT NOT NULL');
        } catch (\PDOException $e) {
            // bereits angepasst
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS interventions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel_id VARCHAR(32) NOT NULL,
            user_ids TEXT NOT NULL,
            heat INT NOT NULL,
            created_at DATETIME NOT NULL
        ) CHARACTER SET utf8mb4');
    }
}
