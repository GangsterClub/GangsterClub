<?php

namespace src\Migration;

class CreateAuthRateLimit extends \app\Middleware\Migration
{
    protected array $tables = ['auth_rate_limit'];

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `auth_rate_limit` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `scope` VARCHAR(32) NOT NULL,
            `identifier_hash` CHAR(64) NOT NULL,
            `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `by_scope_identifier` (`scope`, `identifier_hash`),
            KEY `by_expires_at` (`expires_at`)
        )";
        $this->execute($sql);
        $this->log("AuthRateLimit created successfully.");
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `auth_rate_limit`";
        $this->execute($sql);
        $this->log("AuthRateLimit dropped successfully.");
    }
}
