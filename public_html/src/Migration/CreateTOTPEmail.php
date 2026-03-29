<?php

namespace src\Migration;

class CreateTOTPEmail extends \app\Middleware\Migration
{
    protected array $tables = ['totp_email'];

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `totp_email` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(8) NOT NULL,
            `totp_secret` VARCHAR(255) NOT NULL,
            `expires_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `by_user_id_by_totp` (`user_id`, `totp_secret`)
        )";
        $this->execute($sql);
        $this->log("TOTPEmail created successfully.");
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `totp_email`";
        $this->execute($sql);
        $this->log("TOTPEmail dropped successfully.");
    }
}
