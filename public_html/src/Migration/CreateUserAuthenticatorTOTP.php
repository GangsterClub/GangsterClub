<?PHP

declare(strict_types=1);

namespace src\Migration;

class CreateUserAuthenticatorTOTP extends \app\Middleware\Migration
{
    private const TABLE = 'user_authenticator_totp';
    private const LEGACY_TABLE = 'user_mfa_totp';

    protected array $tables = [self::TABLE];

    public function up(): void
    {
        if ($this->tableExists(self::TABLE) === false && $this->tableExists(self::LEGACY_TABLE) === true) {
            $this->execute(sprintf(
                'RENAME TABLE `%s` TO `%s`',
                self::LEGACY_TABLE,
                self::TABLE
            ));
            $this->log('User authenticator TOTP table renamed successfully.');
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS `user_authenticator_totp` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(8) NOT NULL,
            `secret` VARCHAR(128) NOT NULL,
            `digits` TINYINT(2) NOT NULL DEFAULT 6,
            `period` SMALLINT(5) NOT NULL DEFAULT 30,
            `enabled_at` DATETIME NOT NULL,
            `last_verified_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `user_authenticator_totp_user_id_unique` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->execute($sql);
        $this->log("UserAuthenticatorTOTP created successfully.");
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `user_authenticator_totp`";
        $this->execute($sql);
        $this->log("UserAuthenticatorTOTP dropped successfully.");
    }

    private function tableExists(string $table): bool
    {
        $connection = $this->dbh->getConnection();
        if ($connection === null) {
            return false;
        }

        $statement = $connection->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }
}
