<?PHP

require_once __DIR__ . '/../vendor/autoload.php';

use src\Data\Connection;
use app\Migration\MigrationManager;

$db = new Connection();

$migrationManager = new MigrationManager($db);

// Add migrations here
$migrationManager->addMigration(new \app\Migration\CreateTOTPEmail());

if ($argv[1] === 'migrate') {
    $migrationManager->migrate();
    echo "Migrations applied successfully.";
} else if ($argv[1] === 'rollback') {
    $migrationManager->rollback();
    echo "Migrations rolled back successfully.";
} else {
    echo "Invalid command. Use 'migrate' or 'rollback'.";
}
