<?PHP

require_once __DIR__ . '/../vendor/autoload.php';

use src\Data\Connection;
use app\Middleware\MigrationPipeline;

$dbh = new Connection();
$migrationManager = new MigrationPipeline($dbh);

// Add migrations here
$migrationManager->addMigration(new \src\Migration\CreateTOTPEmail($dbh));
$migrationManager->addMigration(new \src\Migration\CreateUser($dbh));

$allowedArgs = ['--migrate', '--rollback', '-m', '-r'];
if (isset($argv[1]) === false || in_array($argv[1], $allowedArgs) === false) {
    print_r("Invalid command. Use '-m = --migrate' or '-r = --rollback'." . PHP_EOL);
}

if (isset($argv[1]) === true) {
    $mArgs = [$allowedArgs[0], $allowedArgs[2]];
    if (in_array($argv[1], $mArgs) === true) {
        $migrationManager->migrate();
        print_r("Migrations applied successfully." . PHP_EOL);
    }

    $rArgs = [$allowedArgs[1], $allowedArgs[3]];
    if (in_array($argv[1], $rArgs) === true) {
        $migrationManager->rollback();
        print_r("Migrations rolled back successfully." . PHP_EOL);
    }
}
