<?php

declare(strict_types=1);

namespace src\Migration;

class CreateRecoveryCodes extends \app\Middleware\Migration
{
    protected array $tables = [
        'recovery_code_set',
        'recovery_code',
        'user_recovery_code_state',
    ];

    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `recovery_code_set` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `purpose` VARCHAR(80) NOT NULL,
                `status` VARCHAR(20) NOT NULL,
                `authenticator_generation` INT UNSIGNED NOT NULL DEFAULT 1,
                `authentication_challenge_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `replaces_recovery_code_set_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `displayed_at` DATETIME NULL DEFAULT NULL,
                `acknowledged_at` DATETIME NULL DEFAULT NULL,
                `invalidated_at` DATETIME NULL DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `recovery_code_set_user_status` (`user_id`, `status`),
                KEY `recovery_code_set_challenge` (`authentication_challenge_id`),
                KEY `recovery_code_set_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->execute(
            "CREATE TABLE IF NOT EXISTS `recovery_code` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recovery_code_set_id` BIGINT UNSIGNED NOT NULL,
                `code_hash` CHAR(64) NOT NULL,
                `used_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `recovery_code_hash_unique` (`code_hash`),
                KEY `recovery_code_set_unused` (`recovery_code_set_id`, `used_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->execute(
            "CREATE TABLE IF NOT EXISTS `user_recovery_code_state` (
                `user_id` INT PRIMARY KEY,
                `active_recovery_code_set_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `user_recovery_code_active_set_unique` (`active_recovery_code_set_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if ($this->columnExists('user_authenticator_totp', 'generation') === false) {
            $this->execute(
                'ALTER TABLE `user_authenticator_totp` ADD COLUMN `generation` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `period`'
            );
        }
        $this->log('RecoveryCodes created successfully.');
    }

    public function down(): void
    {
        if ($this->columnExists('user_authenticator_totp', 'generation') === true) {
            $this->execute('ALTER TABLE `user_authenticator_totp` DROP COLUMN `generation`');
        }
        $this->execute('DROP TABLE IF EXISTS `user_recovery_code_state`');
        $this->execute('DROP TABLE IF EXISTS `recovery_code`');
        $this->execute('DROP TABLE IF EXISTS `recovery_code_set`');
        $this->log('RecoveryCodes dropped successfully.');
    }

    private function columnExists(string $table, string $column): bool
    {
        $connection = $this->dbh->getConnection();
        if ($connection === null) {
            return false;
        }

        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }
}
