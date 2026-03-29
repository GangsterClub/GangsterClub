<?PHP

declare(strict_types=1);

namespace app\Middleware;

class Migration implements MigrationInterface
{
    protected $dbh;
    protected array $tables = [];

    public function __construct(\src\Data\Connection $dbh)
    {
        $this->dbh = $dbh;
    }

    public function up(): void
    {
        // Define the schema changes for the migration
    }

    public function down(): void
    {
        // Define the rollback changes for the migration
    }

    public function log(string $message): void
    {
        print_r($message . PHP_EOL);
    }

    protected function execute(string $sql): void
    {
        $this->dbh->query($sql);
    }

    public function getTables(): array
    {
        return $this->tables;
    }
}
