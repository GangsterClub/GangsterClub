<?php

declare(strict_types=1);

namespace src\Migration;

final class AddTwoStepVerificationPreference extends \app\Console\Migration
{
    protected array $tables = ['user'];

    public function up(): void
    {
        if ($this->columnExists('user', 'require_two_step_verification') === false) {
            $this->execute(
                'ALTER TABLE `user` ADD COLUMN `require_two_step_verification` '
                . 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `browser_session_version`'
            );
        }
        $this->log('Two-step verification preference added successfully.');
    }

    public function down(): void
    {
        if ($this->columnExists('user', 'require_two_step_verification') === true) {
            $this->execute('ALTER TABLE `user` DROP COLUMN `require_two_step_verification`');
        }
        $this->log('Two-step verification preference dropped successfully.');
    }

    private function columnExists(string $table, string $column): bool
    {
        $connection = $this->dbh->getConnection();
        if ($connection === null) {
            return false;
        }

        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }
}
