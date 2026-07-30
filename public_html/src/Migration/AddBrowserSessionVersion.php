<?php

declare(strict_types=1);

namespace src\Migration;

class AddBrowserSessionVersion extends \app\Middleware\Migration
{
    protected array $tables = ['user'];

    public function up(): void
    {
        if ($this->columnExists('user', 'browser_session_version') === false) {
            $this->execute(
                'ALTER TABLE `user` ADD COLUMN `browser_session_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `ip_address`'
            );
        }
        $this->log('Browser session version added successfully.');
    }

    public function down(): void
    {
        if ($this->columnExists('user', 'browser_session_version') === true) {
            $this->execute('ALTER TABLE `user` DROP COLUMN `browser_session_version`');
        }
        $this->log('Browser session version removed successfully.');
    }

    protected function columnExists(string $table, string $column): bool
    {
        $connection = $this->dbh->getConnection();
        if ($connection === null) {
            return false;
        }

        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }
}
