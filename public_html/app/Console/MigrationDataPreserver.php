<?php

declare(strict_types=1);

namespace app\Console;

use PDO;
use RuntimeException;
use src\Data\Connection;

class MigrationDataPreserver
{
    private PDO $pdo;

    private MigrationSnapshotStore $snapshotStore;
    private MigrationTableRestorer $tableRestorer;

    public function __construct(
        Connection $connection,
        ?string $snapshotDirectory = null,
        ?MigrationSnapshotStore $snapshotStore = null,
        ?MigrationTableRestorer $tableRestorer = null
    )
    {
        $pdo = $connection->getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Unable to initialise migration data preserver without an active database connection.');
        }

        $this->pdo = $pdo;
        $baseDirectory = $snapshotDirectory ?? dirname(__DIR__) . '/storage/tmp/migrations';
        $this->snapshotStore = $snapshotStore ?? new MigrationSnapshotStore($baseDirectory);
        $this->tableRestorer = $tableRestorer ?? new MigrationTableRestorer($pdo);
    }

    public function backup(array $tables): bool
    {
        $tables = $this->normaliseTables($tables);
        if ($tables === []) {
            $this->snapshotStore->delete();
            return false;
        }

        $payload = [
            'created_at' => time(),
            'tables' => [],
        ];

        foreach ($tables as $table) {
            if ($this->tableExists($table) === false) {
                continue;
            }

            $payload['tables'][$table] = [
                'rows' => $this->fetchTableData($table),
            ];
        }

        if ($payload['tables'] === []) {
            $this->snapshotStore->delete();
            return false;
        }

        $this->snapshotStore->write($payload);
        return true;
    }

    public function restore(?array $allowedTables = null): void
    {
        if ($this->snapshotStore->exists() === false) {
            return;
        }

        $payload = $this->snapshotStore->read();
        if ($payload === null || isset($payload['tables']) === false) {
            $this->snapshotStore->delete();
            return;
        }

        if ($allowedTables === null) {
            $this->snapshotStore->delete();
            return;
        }

        $tables = $this->normaliseTables($allowedTables);
        foreach ($tables as $table) {
            $tablePayload = $payload['tables'][$table] ?? null;
            if (is_array($tablePayload) === false) {
                continue;
            }

            $rows = $tablePayload['rows'] ?? [];
            if ($rows === [] || $this->tableExists($table) === false) {
                continue;
            }

            $this->tableRestorer->restore($table, $rows);
        }

        $this->snapshotStore->delete();
    }

    public function hasSnapshot(): bool
    {
        return $this->snapshotStore->exists();
    }

    private function fetchTableData(string $table): array
    {
        $statement = $this->pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $data === false ? [] : $data;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    private function normaliseTables(array $tables): array
    {
        $tables = array_filter(array_map('strval', $tables));
        $tables = array_filter($tables, [$this, 'isValidIdentifier']);
        return array_values(array_unique($tables));
    }

    private function isValidIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }
}
