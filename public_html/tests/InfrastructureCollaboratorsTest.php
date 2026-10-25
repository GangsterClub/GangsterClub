<?php

declare(strict_types=1);

use app\Console\MigrationSnapshotStore;
use app\Console\MigrationTableRestorer;
use app\Service\AuthSessionKeys;
use app\Service\FlashBag;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use src\Business\DatabaseRetryClassifier;
use src\Data\Connection;
use src\Data\Repository\UserLockRepository;

require_once __DIR__ . '/../vendor/autoload.php';

final class CollaboratorTestSession extends SessionService
{
    public array $values = [];

    public function __construct() {}
    public function get(string $key, $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
}

final class FailingRestoreStatement extends PDOStatement
{
    public function __construct(private readonly bool $describe = false) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->describe === true ? [['Field' => 'id']] : [];
    }
    public function execute(?array $params = null): bool
    {
        throw new RuntimeException('simulated restore failure');
    }
}

final class FailingRestorePdo extends PDO
{
    public bool $began = false;
    public bool $rolledBack = false;

    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new FailingRestoreStatement(true);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FailingRestoreStatement();
    }
    public function beginTransaction(): bool { $this->began = true; return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { $this->rolledBack = true; return true; }
}

function collaboratorAssert(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

$session = new CollaboratorTestSession();
$pending = new PendingAuthenticationSession($session);
$pending->setEmail('person@example.test');
$pending->setTotp('123456');
$pending->setUserId(42);
$pending->setAuthenticatorRequired(true);
$pending->setTwoStepRequired(true);
$pending->clear();
foreach ([
    AuthSessionKeys::PENDING_LOGIN_EMAIL,
    AuthSessionKeys::PENDING_LOGIN_TOTP,
    AuthSessionKeys::PENDING_USER_ID,
    AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED,
    AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED,
] as $key) {
    collaboratorAssert(array_key_exists($key, $session->values) === false, 'Pending authentication clear left ' . $key . '.');
}

$security = new SecurityFlowSession($session);
$security->setPendingAuthenticatorSecret('secret');
$security->setChallenge('token', 'purpose');
$security->setPendingRecoverySetId(7);
$security->markRecoveryCodesDelivered(7);
$session->set(AuthSessionKeys::SECURITY_EMAIL_SECRET, 'email-secret');
$security->clear();
foreach ([
    AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET,
    AuthSessionKeys::SECURITY_CHALLENGE_TOKEN,
    AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE,
    AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID,
    AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED,
    AuthSessionKeys::SECURITY_EMAIL_SECRET,
] as $key) {
    collaboratorAssert(array_key_exists($key, $session->values) === false, 'Security-flow clear left ' . $key . '.');
}

$flash = new FlashBag($session);
$flash->add('login', 'errors', 'first');
$flash->add('login', 'success', 'second');
collaboratorAssert(
    $flash->consume('login') === ['errors' => ['first'], 'success' => ['second']],
    'Flash consumption changed the message shape.'
);
collaboratorAssert(
    $flash->consume('login') === ['errors' => [], 'success' => []],
    'Flash messages were not consumed exactly once.'
);

$classifier = new DatabaseRetryClassifier();
$deadlock = new PDOException('deadlock', 40001);
$deadlock->errorInfo = ['40001', 1213, 'deadlock'];
$timeout = new PDOException('timeout');
$timeout->errorInfo = ['HY000', 1205, 'timeout'];
$foreignKey = new PDOException('foreign key', 23000);
$foreignKey->errorInfo = ['23000', 1452, 'foreign key'];
collaboratorAssert($classifier->isRetryableTransactionFailure($deadlock), 'MariaDB deadlocks must remain retryable.');
collaboratorAssert($classifier->isRetryableTransactionFailure($timeout), 'MariaDB lock timeouts must remain retryable.');
collaboratorAssert($classifier->isUniqueConstraintViolation($foreignKey) === false, 'Foreign-key failures must not be classified as duplicate entries.');

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO user (id) VALUES (42)');
$locks = new UserLockRepository(new Connection($pdo));
collaboratorAssert($locks->lock(42), 'An existing user must be lockable.');
collaboratorAssert($locks->lock(99) === false, 'A missing user must not appear lockable.');

$snapshotDirectory = sys_get_temp_dir() . '/gangsterclub-snapshot-' . bin2hex(random_bytes(8));
$snapshot = new MigrationSnapshotStore($snapshotDirectory);
$snapshot->write(['tables' => ['user' => ['rows' => [['id' => 42]]]]]);
collaboratorAssert($snapshot->read()['tables']['user']['rows'][0]['id'] === 42, 'Snapshot storage changed its payload.');
$snapshot->delete();
rmdir($snapshotDirectory);

$failingPdo = new FailingRestorePdo();
try {
    (new MigrationTableRestorer($failingPdo))->restore('user', [['id' => 42]]);
    throw new RuntimeException('A restore persistence failure should propagate.');
} catch (RuntimeException $exception) {
    collaboratorAssert($exception->getMessage() === 'simulated restore failure', 'Unexpected restore failure propagated.');
}
collaboratorAssert($failingPdo->began && $failingPdo->rolledBack, 'Failed snapshot restoration must roll back its transaction.');

fwrite(STDOUT, "Infrastructure collaborator tests passed.\n");
