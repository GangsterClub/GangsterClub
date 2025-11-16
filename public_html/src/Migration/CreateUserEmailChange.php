<?PHP

declare(strict_types=1);

namespace src\Migration;

class CreateUserEmailChange extends \app\Middleware\Migration
{
    protected array $tables = ['user_email_change'];

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `user_email_change` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(8) NOT NULL,
            `new_email` VARCHAR(255) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `confirmed_at` DATETIME NULL DEFAULT NULL,
            UNIQUE KEY `user_email_change_token_unique` (`token_hash`),
            INDEX `user_email_change_user_id_index` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->execute($sql);
        $this->log("UserEmailChange created successfully.");
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `user_email_change`";
        $this->execute($sql);
        $this->log("UserEmailChange dropped successfully.");
    }
}
