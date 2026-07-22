<?php

declare(strict_types=1);

namespace src\Data;

use InvalidArgumentException;

class QueryBuilder
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'LIKE', 'NOT LIKE', 'IS', 'IS NOT', 'IN', 'NOT IN',
    ];

    protected \PDO $connection;

    protected string $table;
    protected array $wheres = [];
    protected ?int $limit = null;
    protected array $orderBys = [];
    protected ?array $updates = null;

    public function __construct(\PDO $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = self::validateIdentifier($table, 'table');
    }

    /**
     * Add where condition
     *
     * @param string $column
     * @param mixed $operator
     * @param mixed $value
     * @return self
     */
    public function where(string $column, $operator, $value = null): self
    {
        $column = self::validateIdentifier($column, 'column');

        if ($value === null && !$this->isExplicitNullOperator($operator)) {
            $value = $operator;
            $operator = '=';
        }

        $operator = self::validateOperator((string) $operator);

        if (in_array($operator, ['IN', 'NOT IN'], true)) {
            if (!is_array($value) || $value === []) {
                throw new InvalidArgumentException("Operator {$operator} requires a non-empty array value.");
            }

            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            $this->wheres[] = ["{$column} {$operator} ({$placeholders})", array_values($value)];
            return $this;
        }

        if (in_array($operator, ['IS', 'IS NOT'], true) && $value === null) {
            $this->wheres[] = ["{$column} {$operator} NULL", []];
            return $this;
        }

        $this->wheres[] = ["{$column} {$operator} ?", [$value]];
        return $this;
    }

    /**
     * Add a limit
     *
     * @param int $limit
     * @return self
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Limit must be a non-negative integer.');
        }

        $this->limit = $limit;
        return $this;
    }

    /**
     * Add an order by clause
     *
     * @param string $column
     * @param string $direction
     * @return self
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $column = self::validateIdentifier($column, 'column');
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBys[] = [$column, $direction];
        return $this;
    }

    /**
     * Fetch the first record
     *
     * @return mixed
     */
    public function first()
    {
        $bindings = [];
        $query = $this->buildSelectQuery($bindings);
        $query .= " LIMIT 1";

        $stmt = $this->connection->prepare($query);
        $stmt->execute($bindings);
        return $stmt->fetch();
    }

    /**
     * Fetch all matching records
     *
     * @return array
     */
    public function get(): array
    {
        $bindings = [];
        $query = $this->buildSelectQuery($bindings);

        if ($this->limit !== null) {
            $query .= " LIMIT {$this->limit}";
        }

        $stmt = $this->connection->prepare($query);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    /**
     * Insert record
     * 
     * @param array $data
     * @return bool
     */
    public function insert(array $data): bool
    {
        $columns = array_map(function ($column): string {
            return self::validateIdentifier((string) $column, 'column');
        }, array_keys($data));

        if ($columns === []) {
            throw new InvalidArgumentException('Insert data cannot be empty.');
        }

        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $query = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        $stmt = $this->connection->prepare($query);
        return (bool) $stmt->execute(array_values($data));
    }

    /**
     * Update records
     *
     * @param array $data
     * @return bool
     */
    public function update(array $data): bool
    {
        if ($data === []) {
            throw new InvalidArgumentException('Update data cannot be empty.');
        }

        $query = "UPDATE {$this->table} SET " . implode(', ', array_map(function ($key): string {
            return self::validateIdentifier((string) $key, 'column') . " = ?";
        }, array_keys($data)));

        $bindings = array_values($data);
        $query .= $this->buildWhereClause($bindings);

        $stmt = $this->connection->prepare($query);
        return (bool) $stmt->execute($bindings);
    }

    /**
     * Delete records
     *
     * @return bool
     */
    public function delete(): bool
    {
        $query = "DELETE FROM {$this->table}";
        $bindings = [];
        $query .= $this->buildWhereClause($bindings);

        $stmt = $this->connection->prepare($query);
        return (bool) $stmt->execute($bindings);
    }

    private static function validateIdentifier(string $identifier, string $type): string
    {
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw new InvalidArgumentException("Invalid {$type} identifier: {$identifier}");
        }

        return $identifier;
    }

    private static function validateOperator(string $operator): string
    {
        $operator = strtoupper(preg_replace('/\s+/', ' ', trim($operator)) ?? '');

        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid where operator: {$operator}");
        }

        return $operator;
    }

    private function isExplicitNullOperator($operator): bool
    {
        if (!is_string($operator)) {
            return false;
        }

        $operator = strtoupper(preg_replace('/\s+/', ' ', trim($operator)) ?? '');
        return in_array($operator, ['IS', 'IS NOT'], true);
    }

    private function buildSelectQuery(array &$bindings): string
    {
        $query = "SELECT * FROM {$this->table}";
        $query .= $this->buildWhereClause($bindings);

        if ((bool) $this->orderBys === true) {
            $query .= " ORDER BY " . implode(', ', array_map(function ($orderBy) {
                return "{$orderBy[0]} {$orderBy[1]}";
            }, $this->orderBys));
        }

        return $query;
    }

    private function buildWhereClause(array &$bindings): string
    {
        if ((bool) $this->wheres !== true) {
            return '';
        }

        return " WHERE " . implode(' AND ', array_map(function ($where) use (&$bindings) {
            foreach ($where[1] as $binding) {
                $bindings[] = $binding;
            }

            return $where[0];
        }, $this->wheres));
    }
}
