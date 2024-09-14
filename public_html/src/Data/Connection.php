<?PHP

declare(strict_types=1);

namespace src\Data;

class Connection
{
    /**
     * Summary of connection
     * @var \PDO
     */
    private \PDO $connection;

    /**
     * Summary of __construct
     */
    public function __construct()
    {
        $dsn = DB_CONN_STRING . ";charset=utf8mb4";
        $this->connection = new \PDO($dsn, DB_USER, DB_PASS, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ
        ]);
    }

    /**
     * Summary of getConnection
     * @return \PDO
     */
    public function getConnection()
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
        $this->connection->prepare($query, $params)->execute();
    }
}
