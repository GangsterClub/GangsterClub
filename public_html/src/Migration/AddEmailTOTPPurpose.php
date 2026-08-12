<?php

declare(strict_types=1);

namespace src\Migration;

class AddEmailTOTPPurpose extends \app\Console\Migration
{
    protected array $tables = ['email_totp'];

    public function up(): void
    {
        if ($this->columnExists('email_totp', 'purpose') === false) {
            $this->execute('ALTER TABLE `email_totp` ADD COLUMN `purpose` VARCHAR(64) NULL AFTER `user_id`, ADD KEY `email_totp_active_intent` (`user_id`, `purpose`, `expires_at`, `created_at`)');
        }
        $this->log('Email TOTP purpose binding added successfully. Legacy NULL-purpose rows remain non-redeemable.');
    }

    public function down(): void
    {
        if ($this->columnExists('email_totp', 'purpose') === true) {
            $this->execute('ALTER TABLE `email_totp` DROP KEY `email_totp_active_intent`, DROP COLUMN `purpose`');
        }
        $this->log('Email TOTP purpose binding dropped successfully.');
    }

    protected function columnExists(string $table, string $column): bool
    {
        $connection = $this->dbh->getConnection();
        if ($connection === null) {
            return false;
        }
        $statement = $connection->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute([$table, $column]);
        return (int) $statement->fetchColumn() > 0;
    }
}
