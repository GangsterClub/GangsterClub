<?php

namespace app\Migration;

class CreateTOTPEmail extends Migration
{
    /**
     * Summary of up
     * @return void
     */
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `totp_email` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(8) NOT NULL,
            `totp` INT(12) NOT NULL,
            `expires_at` DECIMAL(14, 4) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `by_user_id_by_totp` (`user_id`, `totp`)
        )";
        $this->execute($sql);
        $this->log("TOTPEmail created successfully.");
    }

    /**
     * Summary of down
     * @return void
     */
    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `totp_email`";
        $this->execute($sql);
        $this->log("TOTPEmail dropped successfully.");
    }
}
