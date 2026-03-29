<?PHP

declare(strict_types=1);

namespace app\Middleware;

use src\Data\Connection;

class MigrationPipeline
{
    protected array $migrations = [];

    protected MigrationDataPreserver $dataPreserver;

    public function __construct(Connection $connection)
    {
        $this->dataPreserver = new MigrationDataPreserver($connection);
    }

    public function addMigration(Migration $migration)
    {
        $this->migrations[] = $migration;
    }

    public function migrate()
    {
        foreach ($this->migrations as $migration) {
            $migration->up();
        }

        $this->dataPreserver->restore($this->collectTables());
    }

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

    protected function collectTables(): array
    {
        $tables = [];
        foreach ($this->migrations as $migration) {
            $tables = array_merge($tables, $migration->getTables());
        }

        return array_values(array_unique($tables));
    }
}
