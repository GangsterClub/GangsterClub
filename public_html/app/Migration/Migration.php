<?PHP

namespace app\Migration;

use src\Data\Connection;

class Migration implements MigrationInterface
{
    protected $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function up()
    {
        // Define the schema changes for the migration
    }

    public function down()
    {
        // Define the rollback changes for the migration
    }

    public function log(string $message)
    {
        print_r($message ."/n");
    }

    protected function execute(string $sql)
    {
        $this->db->query($sql);
    }
}
