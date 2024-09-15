<?PHP

declare(strict_types=1);

namespace app\Migration;

class Migration implements MigrationInterface
{
    /**
     * Summary of dbh
     * @var \src\Data\Connection
     */
    protected $dbh;

    public function __construct(\src\Data\Connection $dbh)
    {
        $this->dbh = $dbh;
    }

    /**
     * Summary of up
     * @return void
     */
    public function up(): void
    {
        // Define the schema changes for the migration
    }

    /**
     * Summary of down
     * @return void
     */
    public function down(): void
    {
        // Define the rollback changes for the migration
    }

    /**
     * Summary of log
     * @param string $message
     * @return void
     */
    public function log(string $message): void
    {
        print_r($message . PHP_EOL);
    }
    /**
     * Summary of execute
     * @param string $sql
     * @return void
     */
    protected function execute(string $sql): void
    {
        $this->dbh->query($sql);
    }
}
