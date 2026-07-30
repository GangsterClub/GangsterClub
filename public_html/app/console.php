<?PHP

require_once __DIR__ . '/../vendor/autoload.php';

use src\Data\Connection;
use app\Middleware\MigrationPipeline;

$dbh = new Connection();
$migrationManager = new MigrationPipeline($dbh);

// Add migrations here
$migrationManager->addMigration(new \src\Migration\CreateUser($dbh));
$migrationManager->addMigration(new \src\Migration\CreateEmailTOTP($dbh));
$migrationManager->addMigration(new \src\Migration\CreateUserAuthenticatorTOTP($dbh));
$migrationManager->addMigration(new \src\Migration\CreateUserEmailChange($dbh));
$migrationManager->addMigration(new \src\Migration\CreateAuthenticationChallenge($dbh));
$migrationManager->addMigration(new \src\Migration\CreateAuthenticationRateLimit($dbh));
$migrationManager->addMigration(new \src\Migration\CreateSecurityAuditEvent($dbh));
$migrationManager->addMigration(new \src\Migration\CreateRecoveryCodes($dbh));
$migrationManager->addMigration(new \src\Migration\AddBrowserSessionVersion($dbh));

$allowedArgs = ['--migrate', '--rollback', '-m', '-r'];
if (isset($argv[1]) === false || in_array($argv[1], $allowedArgs) === false) {
    fwrite(STDOUT, "Invalid command. Use '-m = --migrate' or '-r = --rollback'." . PHP_EOL);
}

if (isset($argv[1]) === true) {
    $mArgs = [$allowedArgs[0], $allowedArgs[2]];
    if (in_array($argv[1], $mArgs) === true) {
        $migrationManager->migrate();
        fwrite(STDOUT, "Migrations applied successfully." . PHP_EOL);
    }

    $rArgs = [$allowedArgs[1], $allowedArgs[3]];
    if (in_array($argv[1], $rArgs) === true) {
        $migrationManager->rollback();
        fwrite(STDOUT, "Migrations rolled back successfully." . PHP_EOL);
    }
}
