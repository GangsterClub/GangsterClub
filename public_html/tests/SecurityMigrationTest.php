<?php

declare(strict_types=1);

use src\Data\Connection;
use src\Migration\CreateAuthenticationChallenge;
use src\Migration\CreateAuthenticationRateLimit;
use src\Migration\CreateRecoveryCodes;
use src\Migration\CreateSecurityAuditEvent;

require_once __DIR__ . '/../vendor/autoload.php';

final class SecurityMigrationTestConnection extends Connection
{
    public array $queries = [];

    public function __construct()
    {
    }

    public function query(string $query, array $params = []): void
    {
        $this->queries[] = $query;
    }
}

function assertMigrationTrue(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

$migrationClasses = [
    CreateAuthenticationChallenge::class => ['authentication_challenge'],
    CreateAuthenticationRateLimit::class => ['authentication_rate_limit'],
    CreateSecurityAuditEvent::class => ['security_audit_event'],
    CreateRecoveryCodes::class => [
        'recovery_code_set',
        'recovery_code',
        'user_recovery_code_state',
    ],
];

foreach ($migrationClasses as $migrationClass => $expectedTables) {
    $connection = new SecurityMigrationTestConnection();
    $migration = new $migrationClass($connection);
    ob_start();
    $migration->up();
    $migration->down();
    ob_end_clean();

    $sql = implode("\n", $connection->queries);
    foreach ($expectedTables as $table) {
        assertMigrationTrue(
            str_contains($sql, 'CREATE TABLE IF NOT EXISTS `' . $table . '`'),
            $migrationClass . ' should create ' . $table . ' idempotently.'
        );
        assertMigrationTrue(
            str_contains($sql, 'DROP TABLE IF EXISTS `' . $table . '`'),
            $migrationClass . ' should provide an isolated rollback for ' . $table . '.'
        );
    }
}

$recoveryConnection = new SecurityMigrationTestConnection();
$recoveryMigration = new CreateRecoveryCodes($recoveryConnection);
ob_start();
$recoveryMigration->up();
$recoveryMigration->down();
ob_end_clean();
$recoverySql = implode("\n", $recoveryConnection->queries);
assertMigrationTrue(
    str_contains($recoverySql, 'ADD COLUMN `generation`'),
    'Recovery migration should add authenticator generation when schema inspection reports it missing.'
);

echo "Security migration tests passed.\n";
