<?PHP

declare(strict_types=1);

namespace src\Migration;

class CreateUserMFATOTP extends \app\Middleware\Migration
{
    protected array $tables = ['user_mfa_totp'];

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `user_mfa_totp` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(8) NOT NULL,
            `secret` VARCHAR(128) NOT NULL,
            `digits` TINYINT(2) NOT NULL DEFAULT 6,
            `period` SMALLINT(5) NOT NULL DEFAULT 30,
            `enabled_at` DATETIME NOT NULL,
            `last_verified_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `user_mfa_totp_user_id_unique` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->execute($sql);
        $this->log("UserMFATOTP created successfully.");
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `user_mfa_totp`";
        $this->execute($sql);
        $this->log("UserMFATOTP dropped successfully.");
    }
}
