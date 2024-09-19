<?PHP

require_once __DIR__ . '/../vendor/autoload.php';

use src\Data\Connection;
use app\Migration\MigrationManager;

$dbh = new Connection();
$migrationManager = new MigrationManager();

// Add migrations here
$migrationManager->addMigration(new \app\Migration\CreateTOTPEmail($dbh));
$migrationManager->addMigration(new \app\Migration\CreateUser($dbh));

$allowedArgs = ['--migrate', '--rollback', '-m', '-r'];
if ((bool) isset($argv[1]) === false || in_array($argv[1], $allowedArgs) === false) {
    print_r("Invalid command. Use '-m = --migrate' or '-r = --rollback'." . PHP_EOL);
}

if ((bool) isset($argv[1]) === true) {
    $mArgs = ['-m', $allowedArgs[0]];
    if (in_array($argv[1], $mArgs) === true) {
        $migrationManager->migrate();
        print_r("Migrations applied successfully." . PHP_EOL);
    }

    $rArgs = ['-r', $allowedArgs[1]];
    if (in_array($argv[1], $rArgs) === true) {
        $migrationManager->rollback();
        print_r("Migrations rolled back successfully." . PHP_EOL);
    }
}
