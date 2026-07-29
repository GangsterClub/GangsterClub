<?php

namespace src\Migration;

class CreateEmailTOTP extends \app\Middleware\Migration
{
    private const TABLE = 'email_totp';
    private const LEGACY_TABLE = 'totp_email';

    protected array $tables = [self::TABLE];

    public function up(): void
    {
        if ($this->tableExists(self::TABLE) === false && $this->tableExists(self::LEGACY_TABLE) === true) {
            $this->execute(sprintf(
                'RENAME TABLE `%s` TO `%s`',
                self::LEGACY_TABLE,
                self::TABLE
            ));
            $this->log('Email TOTP table renamed successfully.');
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS `email_totp` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(8) NOT NULL,
            `totp_secret` VARCHAR(255) NOT NULL,
            `expires_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `by_user_id_by_totp` (`user_id`, `totp_secret`)
        )";
        $this->execute($sql);
        $this->log("EmailTOTP created successfully.");
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `email_totp`";
        $this->execute($sql);
        $this->log("EmailTOTP dropped successfully.");
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
