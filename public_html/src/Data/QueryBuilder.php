<?php

declare(strict_types=1);

namespace src\Data;

class QueryBuilder
{
    /**
     * @var \PDO
     */
    protected \PDO $connection;

    /**
     * @var string
     */
    protected string $table;

    /**
     * @var array
     */
    protected array $wheres = [];

    /**
     * @var ?int
     */
    protected ?int $limit = null;

    /**
     * @var ?array
     */
    protected ?array $updates = null;

    /**
     * Constructor
     *
     * @param \PDO $connection
     * @param string $table
     */
    public function __construct(\PDO $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = $table;
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
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        $this->wheres[] = [$column, $operator, $value];
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
        $this->limit = $limit;
        return $this;
    }

    /**
     * Fetch the first record
     *
     * @return mixed
     */
    public function first()
    {
        $query = "SELECT * FROM {$this->table}";
        $bindings = [];

        if ((bool) $this->wheres === true) {
            $query .= " WHERE " . implode(' AND ', array_map(function ($where) use (&$bindings) {
                $bindings[] = $where[2];
                return "{$where[0]} {$where[1]} ?";
            }, $this->wheres));
        }

        $query .= " LIMIT 1";

        $stmt = $this->connection->prepare($query);
        $stmt->execute($bindings);
        return $stmt->fetch();
    }

    /**
     * Insert record
     * 
     * @param array $data
     * @return bool
     */
    public function insert(array $data): bool
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $query = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
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
        $query = "UPDATE {$this->table} SET " . implode(', ', array_map(function ($key) {
            return "{$key} = ?";
        }, array_keys($data)));

        $bindings = array_values($data);
        if ((bool) $this->wheres === true) {
            $query .= " WHERE " . implode(' AND ', array_map(function ($where) use (&$bindings) {
                $bindings[] = $where[2];
                return "{$where[0]} {$where[1]} ?";
            }, $this->wheres));
        }

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
        if ((bool) $this->wheres === true) {
            $query .= " WHERE " . implode(' AND ', array_map(function ($where) use (&$bindings) {
                $bindings[] = $where[2];
                return "{$where[0]} {$where[1]} ?";
            }, $this->wheres));
        }

        $stmt = $this->connection->prepare($query);
        return (bool) $stmt->execute($bindings);
    }
}
