<?PHP

namespace app\Migration;

use src\Data\Connection;

class MigrationManager
{
    /**
     * Summary of db
     * @var Connection
     */
    protected Connection $db;

    /**
     * Summary of migrations
     * @var array
     */
    protected array $migrations = [];

    /**
     * Summary of __construct
     * @param \src\Data\Connection $db
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Summary of addMigration
     * @param \app\Migration\Migration $migration
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
    }

    /**
     * Summary of rollback
     * @return void
     */
    public function rollback()
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
    }
}
