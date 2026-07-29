<?php

declare(strict_types=1);

namespace src\Migration;

class CreateAuthenticationRateLimit extends \app\Middleware\Migration
{
    protected array $tables = ['authentication_rate_limit'];

    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `authentication_rate_limit` (
                `bucket_hash` CHAR(64) NOT NULL,
                `purpose` VARCHAR(80) NOT NULL,
                `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `window_started_at` DATETIME NOT NULL,
                `blocked_until` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`bucket_hash`, `purpose`),
                KEY `authentication_rate_limit_blocked_until` (`blocked_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->log('AuthenticationRateLimit created successfully.');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `authentication_rate_limit`');
        $this->log('AuthenticationRateLimit dropped successfully.');
    }
}
