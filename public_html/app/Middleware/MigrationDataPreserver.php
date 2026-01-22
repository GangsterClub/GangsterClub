<?php

declare(strict_types=1);

namespace app\Middleware;

use PDO;
use RuntimeException;
use src\Data\Connection;

class MigrationDataPreserver
{
    private PDO $pdo;

    private string $snapshotDirectory;

    private string $snapshotFile;

    public function __construct(Connection $connection, ?string $snapshotDirectory = null)
    {
        $pdo = $connection->getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Unable to initialise migration data preserver without an active database connection.');
        }

        $this->pdo = $pdo;
        $baseDirectory = $snapshotDirectory ?? dirname(__DIR__) . '/storage/tmp/migrations';
        $this->snapshotDirectory = rtrim($baseDirectory, DIRECTORY_SEPARATOR);
        $this->snapshotFile = $this->snapshotDirectory . '/latest_snapshot.json';

        if (is_dir($this->snapshotDirectory) === false) {
            mkdir($this->snapshotDirectory, 0770, true);
        }
    }

    public function backup(array $tables): bool
    {
        $tables = $this->normaliseTables($tables);
        if ($tables === []) {
            $this->deleteSnapshot();
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
            $this->deleteSnapshot();
            return false;
        }

        $this->writeSnapshot($payload);
        return true;
    }

    public function restore(): void
    {
        if (is_file($this->snapshotFile) === false) {
            return;
        }

        $payload = json_decode((string)file_get_contents($this->snapshotFile), true);
        if (json_last_error() !== JSON_ERROR_NONE || isset($payload['tables']) === false) {
            $this->deleteSnapshot();
            return;
        }

        $tables = $this->normaliseTables(array_keys($payload['tables']));
        foreach ($tables as $table) {
            $tablePayload = $payload['tables'][$table] ?? null;
            if (is_array($tablePayload) === false) {
                continue;
            }

            $rows = $tablePayload['rows'] ?? [];
            if ($rows === [] || $this->tableExists($table) === false) {
                continue;
            }

            $this->restoreTableRows($table, $rows);
        }

        $this->deleteSnapshot();
    }

    public function hasSnapshot(): bool
    {
        return is_file($this->snapshotFile);
    }

    private function restoreTableRows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = $this->describeTable($table);
        if ($columns === []) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->insertRow($table, $columns, $row);
            }
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    private function insertRow(string $table, array $tableColumns, array $row): void
    {
        $insertColumns = array_values(array_intersect($tableColumns, array_keys($row)));
        if ($insertColumns === []) {
            return;
        }

        $quotedColumns = implode(', ', array_map([$this, 'quoteIdentifier'], $insertColumns));
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            $quotedColumns,
            $placeholders
        );

        $statement = $this->pdo->prepare($sql);
        $values = [];
        foreach ($insertColumns as $column) {
            $values[] = $row[$column] ?? null;
        }

        $statement->execute($values);
    }

    private function fetchTableData(string $table): array
    {
        $statement = $this->pdo->query('SELECT * FROM ' . $this->quoteIdentifier($table));
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $data === false ? [] : $data;
    }

    private function describeTable(string $table): array
    {
        $statement = $this->pdo->query('DESCRIBE ' . $this->quoteIdentifier($table));
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (isset($column['Field']) === true) {
                $columns[] = $column['Field'];
            }
        }

        return $columns;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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

    private function writeSnapshot(array $payload): void
    {
        file_put_contents(
            $this->snapshotFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function deleteSnapshot(): void
    {
        if (is_file($this->snapshotFile) === true) {
            unlink($this->snapshotFile);
        }
    }
}
