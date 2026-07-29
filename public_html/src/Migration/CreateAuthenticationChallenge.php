<?php

declare(strict_types=1);

namespace src\Migration;

class CreateAuthenticationChallenge extends \app\Middleware\Migration
{
    protected array $tables = ['authentication_challenge'];

    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `authentication_challenge` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `token_hash` CHAR(64) NOT NULL,
                `user_id` INT NOT NULL,
                `purpose` VARCHAR(80) NOT NULL,
                `state` VARCHAR(80) NOT NULL,
                `session_binding_hash` CHAR(64) NOT NULL,
                `baseline_authenticator_generation` INT UNSIGNED NULL DEFAULT NULL,
                `baseline_recovery_code_set_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `reauthenticated_at` DATETIME NULL DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `completed_at` DATETIME NULL DEFAULT NULL,
                `cancelled_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `authentication_challenge_token_hash_unique` (`token_hash`),
                KEY `authentication_challenge_user_purpose` (`user_id`, `purpose`),
                KEY `authentication_challenge_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->log('AuthenticationChallenge created successfully.');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `authentication_challenge`');
        $this->log('AuthenticationChallenge dropped successfully.');
    }
}
