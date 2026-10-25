<?php

declare(strict_types=1);

namespace app\Console;

use PDO;

final class MigrationTableRestorer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function restore(string $table, array $rows): void
    {
        if ($rows === [] || $this->isValidIdentifier($table) === false) {
            return;
        }

        $columns = $this->describe($table);
        if ($columns === []) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->insert($table, $columns, $row);
            }
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    private function insert(string $table, array $tableColumns, array $row): void
    {
        $tableColumns = array_values(array_filter($tableColumns, [$this, 'isValidIdentifier']));
        $insertColumns = array_values(array_intersect($tableColumns, array_keys($row)));
        if ($insertColumns === []) {
            return;
        }

        $quotedColumns = implode(', ', array_map([$this, 'quote'], $insertColumns));
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($table),
            $quotedColumns,
            $placeholders
        ));
        $statement->execute(array_map(
            static fn (string $column): mixed => $row[$column] ?? null,
            $insertColumns
        ));
    }

    private function describe(string $table): array
    {
        $statement = $this->pdo->query('DESCRIBE ' . $this->quote($table));
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (isset($column['Field']) === true) {
                $columns[] = $column['Field'];
            }
        }
        return $columns;
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function isValidIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }
}
