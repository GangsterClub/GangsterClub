<?PHP

declare(strict_types=1);

namespace src\Data;

class Connection
{
    /**
     * Summary of connection
     * @var \PDO|null
     */
    private \PDO|null $connection = null;
    // private static $instanceCount = 0; // Testing purposes

    /**
     * Summary of __construct
     */
    public function __construct()
    {
        // self::$instanceCount++;
        // error_log("Connecting, instance count: " . self::$instanceCount);
        try {
            $dsn = DB_CONN_STRING . ";charset=utf8mb4";
            $options = [
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ];

            $this->connection = new \PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $exc) {
            $error = "Unable to establish a database connection.";
            if (strtolower(string: ENVIRONMENT) !== "production" && DEVELOPMENT === true) {
                $error = "PDOException caught: " . print_r($exc->getMessage(), true);
                $error = new \PDOException($error);
            }

            die($error);
        }
    }

    public function __destruct()
    {
        // self::$instanceCount--;
        // error_log("Disconnected, instance count: " . self::$instanceCount);
        $this->connection = null;
    }

    /**
     * Summary of getConnection
     * @return \PDO|null
     */
    public function getConnection(): \PDO|null
    {
        return $this->connection;
    }

    /**
     * Summary of table
     * @param string $table
     * @return \src\Data\QueryBuilder
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this->connection, $table);
    }

    /**
     * Summary of query
     * @param string $query
     * @param array $params
     * @return void
     */
    public function query(string $query, array $params = []): void
    {
        $this->connection->prepare($query)->execute($params);
    }
}
