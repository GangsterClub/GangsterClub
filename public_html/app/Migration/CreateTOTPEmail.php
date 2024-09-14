<?php

namespace app\Migration;

class CreateTOTPEmail extends Migration
{
    /**
     * Summary of up
     * @return void
     */
    public function up()
    {
        $sql = "CREATE TABLE IF NOT EXISTS users (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(8) NOT NULL,
            `totp` INT(12) NOT NULL,
            `expires_at` DECIMAL(14, 4) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id),
            UNIQUE (totp)
        )";
        $this->execute($sql);
        $this->log("TOTPEmail created successfully.");
    }

    /**
     * Summary of down
     * @return void
     */
    public function down()
    {
        $sql = "DROP TABLE IF EXISTS `totp_email`";
        $this->execute($sql);
        $this->log("TOTPEmail dropped successfully.");
    }
}
