<?php

namespace src\Migration;

class CreateUser extends \app\Middleware\Migration
{
    /**
     * Summary of up
     * @return void
     */
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `user` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `ip_address` VARCHAR(80) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL DEFAULT NULL,
            UNIQUE (`email`)
        )";
        $this->execute($sql);
        $this->log("User created successfully.");
    }

    /**
     * Summary of down
     * @return void
     */
    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `user`";
        $this->execute($sql);
        $this->log("User dropped successfully.");
    }
}
