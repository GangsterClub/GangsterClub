<?PHP

namespace app\Migration;

interface MigrationInterface
{
    public function up();
    public function down();
    public function log(string $message);
    protected function execute(string $query);
}
