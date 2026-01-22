<?PHP

declare(strict_types=1);

namespace app\Middleware;

use src\Data\Connection;

class MigrationPipeline
{
    /**
     * Summary of migrations
     * @var array
     */
    protected array $migrations = [];

    /**
     * Summary of dataPreserver
     * @var \app\Middleware\MigrationDataPreserver
     */
    protected MigrationDataPreserver $dataPreserver;

    public function __construct(Connection $connection)
    {
        $this->dataPreserver = new MigrationDataPreserver($connection);
    }

    /**
     * Summary of addMigration
     * @param \app\Middleware\Migration $migration
     * @return void
     */
    public function addMigration(Migration $migration)
    {
        $this->migrations[] = $migration;
    }

    /**
     * Summary of migrate
     * @return void
     */
    public function migrate()
    {
        foreach ($this->migrations as $migration) {
            $migration->up();
        }

        $this->dataPreserver->restore($this->collectTables());
    }

    /**
     * Summary of rollback
     * @return void
     */
    public function rollback()
    {
        $tables = $this->collectTables();
        if ($this->dataPreserver->backup($tables) === true) {
            print_r('Database snapshot stored successfully.' . PHP_EOL);
        }

        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
    }

    /**
     * @return array<int, string>
     */
    protected function collectTables(): array
    {
        $tables = [];
        foreach ($this->migrations as $migration) {
            $tables = array_merge($tables, $migration->getTables());
        }

        return array_values(array_unique($tables));
    }
}
