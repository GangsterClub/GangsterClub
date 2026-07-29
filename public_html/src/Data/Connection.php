<?PHP

declare(strict_types=1);

namespace src\Data;

use src\Data\Exception\DatabaseConnectionException;

class Connection
{
    private ?\PDO $connection = null;
    //private static $instanceCount = 0; // Testing purposes

    public function __construct(?\PDO $connection = null)
    {
        if ($connection instanceof \PDO) {
            $this->connection = $connection;
            $this->configureConnection();
            return;
        }

        //self::$instanceCount++;
        //error_log("Connecting, instance count: " . self::$instanceCount);
        try {
            $this->connection = new \PDO(DB_CONN_STRING, DB_USER, DB_PASS);
            $this->configureConnection();
        } catch (\PDOException $exc) {
            /* Sensitive information exposure stored in server logs: */
            error_log(
                "Database connection failure [" . date(DATE_ATOM) . "]: "
                . $exc->getMessage()
                . " in "
                . $exc->getFile()
                . ":"
                . $exc->getLine()
            );

            throw new DatabaseConnectionException($exc);
        }
    }

    public function __destruct()
    {
        //self::$instanceCount--;
        //error_log("Disconnected, instance count: " . self::$instanceCount);
        $this->connection = null;
    }

    private function configureConnection(): void
    {
        if ($this->connection === null) {
            return;
        }

        $this->connection->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function getConnection(): \PDO|null
    {
        return $this->connection;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this->connection, $table);
    }

    public function query(string $query, array $params = []): void
    {
        $this->connection->prepare($query)->execute($params);
    }

    private function beginTransaction(): void
    {
        if ($this->connection === null || $this->connection->beginTransaction() === false) {
            throw new \RuntimeException('Unable to begin database transaction.');
        }
    }

    private function commit(): void
    {
        if ($this->connection === null || $this->connection->commit() === false) {
            throw new \RuntimeException('Unable to commit database transaction.');
        }
    }

    private function rollBack(): void
    {
        if ($this->connection === null || $this->connection->inTransaction() === false) {
            return;
        }

        if ($this->connection->rollBack() === false) {
            throw new \RuntimeException('Unable to roll back database transaction.');
        }
    }

    private function inTransaction(): bool
    {
        return $this->connection !== null && $this->connection->inTransaction();
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->inTransaction()) {
            return $callback($this);
        }

        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $throwable) {
            $this->rollBack();
            throw $throwable;
        }
    }
}
