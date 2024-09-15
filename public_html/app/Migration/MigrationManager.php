<?PHP

declare(strict_types=1);

namespace app\Migration;

class MigrationManager
{
    /**
     * Summary of migrations
     * @var array
     */
    protected array $migrations = [];

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
