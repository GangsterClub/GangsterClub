<?php

declare(strict_types=1);

use src\Business\AuthenticationRateLimitAction;
use src\Business\AuthenticationRateLimitBucketDimension;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\AuthenticationRateLimitService;
use src\Business\RateLimitPolicy;
use src\Data\Connection;
use src\Data\Repository\AuthenticationRateLimitRepository;

require_once __DIR__ . '/../vendor/autoload.php';

function testPolicies(
    int $accountMaximum = 2,
    int $ipMaximum = 10,
    int $sessionMaximum = 4,
    int $challengeMaximum = 2
): array {
    return [
        AuthenticationRateLimitBucketDimension::ACCOUNT->value =>
            new RateLimitPolicy($accountMaximum, 60, 120),

        AuthenticationRateLimitBucketDimension::IP_ADDRESS->value =>
            new RateLimitPolicy($ipMaximum, 60, 120),

        AuthenticationRateLimitBucketDimension::SESSION->value =>
            new RateLimitPolicy($sessionMaximum, 60, 120),

        AuthenticationRateLimitBucketDimension::CHALLENGE->value =>
            new RateLimitPolicy($challengeMaximum, 60, 120),
    ];
}

function assertRateLimitSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertRateLimitTrue(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function assertRateLimitThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function mariaDbPdoException(string $message, string $sqlState, int $driverCode): PDOException
{
    $exception = new PDOException($message, (int) $sqlState);
    $exception->errorInfo = [$sqlState, $driverCode, $message];

    return $exception;
}

class UniqueRaceAuthenticationRateLimitRepository extends AuthenticationRateLimitRepository
{
    public int $insertCalls = 0;

    public function insert(string $bucketHash, string $purpose, string $windowStartedAt): void
    {
        ++$this->insertCalls;
        if ($this->insertCalls === 1) {
            throw mariaDbPdoException('simulated concurrent unique-key race', '23000', 1062);
        }

        parent::insert($bucketHash, $purpose, $windowStartedAt);
    }
}

class RetryableContentionRateLimitRepository extends AuthenticationRateLimitRepository
{
    public int $insertCalls = 0;

    public function __construct(
        Connection $connection,
        private readonly string $sqlState,
        private readonly int $driverCode,
        private readonly int $failuresBeforeSuccess = 1
    ) {
        parent::__construct($connection);
    }

    public function insert(string $bucketHash, string $purpose, string $windowStartedAt): void
    {
        ++$this->insertCalls;
        if ($this->insertCalls <= $this->failuresBeforeSuccess) {
            throw mariaDbPdoException('simulated retryable transaction contention', $this->sqlState, $this->driverCode);
        }

        parent::insert($bucketHash, $purpose, $windowStartedAt);
    }
}

class NonDuplicateIntegrityFailureRateLimitRepository extends AuthenticationRateLimitRepository
{
    public int $insertCalls = 0;

    public function insert(string $bucketHash, string $purpose, string $windowStartedAt): void
    {
        ++$this->insertCalls;
        throw mariaDbPdoException('simulated foreign-key violation', '23000', 1452);
    }
}

class FailingSecondBucketRateLimitRepository extends AuthenticationRateLimitRepository
{
    private int $insertCalls = 0;

    public function insert(string $bucketHash, string $purpose, string $windowStartedAt): void
    {
        ++$this->insertCalls;
        if ($this->insertCalls === 2) {
            throw new RuntimeException('simulated second-bucket persistence failure');
        }

        parent::insert($bucketHash, $purpose, $windowStartedAt);
    }
}

$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo);
$pdo->exec(
    'CREATE TABLE authentication_rate_limit (
        bucket_hash TEXT NOT NULL,
        purpose TEXT NOT NULL,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        blocked_until TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (bucket_hash, purpose)
    )'
);

$clockNow = new DateTimeImmutable('2030-01-02 03:04:05');
$clock = static function () use (&$clockNow): DateTimeImmutable {
    return $clockNow;
};
$pepper = str_repeat('typed-rate-limit-secret-', 2);
$repository = new AuthenticationRateLimitRepository($connection);
$service = new AuthenticationRateLimitService($connection, $repository, $pepper, $clock);
$policies = testPolicies();
$verifyAction = AuthenticationRateLimitAction::RECOVERY_CODE_VERIFY;
$verifyPurpose = AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN;
$clearRows = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM authentication_rate_limit');
};
$consume = static function (
    AuthenticationRateLimitService $rateLimitService,
    AuthenticationRateLimitContext $context,
    AuthenticationRateLimitAction $action,
    AuthenticationRateLimitPurpose $purpose,
    array $rateLimitPolicies
) {
    return $rateLimitService->consumeAttempt($context, $action, $purpose, $rateLimitPolicies);
};

assertRateLimitThrows(
    static fn () => AuthenticationRateLimitContext::forUser(0, '192.0.2.1'),
    'A context must reject a missing authenticated account identity.'
);
assertRateLimitThrows(
    static fn () => AuthenticationRateLimitContext::forEmail('not-an-email', '192.0.2.1'),
    'A context must reject an invalid email account identity.'
);
assertRateLimitThrows(
    static fn () => AuthenticationRateLimitContext::forUser(1, 'not-an-ip'),
    'A context must reject a missing or invalid IP dimension.'
);

$allDimensions = AuthenticationRateLimitContext::forUser(
    1,
    '2001:0db8:0:0:0:0:0:1',
    'session-alpha',
    'challenge-alpha'
);
$consume($service, $allDimensions, $verifyAction, $verifyPurpose, $policies);
assertRateLimitSame(
    4,
    (int) $pdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn(),
    'Mandatory account/IP and optional session/challenge buckets must all be recorded.'
);
assertRateLimitSame(
    4,
    (int) $pdo->query('SELECT SUM(attempt_count) FROM authentication_rate_limit')->fetchColumn(),
    'One action must increment every applicable bucket consistently.'
);

$clearRows();
$firstSession = AuthenticationRateLimitContext::forUser(1, '192.0.2.10', 'session-one');
$consume($service, $firstSession, $verifyAction, $verifyPurpose, $policies);
$consume($service, $firstSession, $verifyAction, $verifyPurpose, $policies);
$newSessionDecision = $consume(
    $service,
    AuthenticationRateLimitContext::forUser(1, '192.0.2.10', 'session-two'),
    $verifyAction,
    $verifyPurpose,
    $policies
);
assertRateLimitSame(false, $newSessionDecision->allowed, 'A new session must not bypass mandatory buckets.');
assertRateLimitTrue(
    in_array(AuthenticationRateLimitBucketDimension::ACCOUNT->value, $newSessionDecision->blockedDimensions, true)
    && in_array(AuthenticationRateLimitBucketDimension::IP_ADDRESS->value, $newSessionDecision->blockedDimensions, true) === false,
    'A session change must leave the exhausted account limit effective without prematurely exhausting the IP limit.'
);

$clearRows();
$originalIp = AuthenticationRateLimitContext::forUser(1, '192.0.2.11');
$consume($service, $originalIp, $verifyAction, $verifyPurpose, $policies);
$consume($service, $originalIp, $verifyAction, $verifyPurpose, $policies);
$changedIpDecision = $consume(
    $service,
    AuthenticationRateLimitContext::forUser(1, '192.0.2.12'),
    $verifyAction,
    $verifyPurpose,
    $policies
);
assertRateLimitSame(false, $changedIpDecision->allowed, 'Changing IP must not bypass the account limit.');
assertRateLimitTrue(
    in_array(AuthenticationRateLimitBucketDimension::ACCOUNT->value, $changedIpDecision->blockedDimensions, true),
    'The account dimension must identify the blocking limit after an IP change.'
);

$clearRows();
$sharedIp = AuthenticationRateLimitContext::forUser(1, '192.0.2.13');
$consume($service, $sharedIp, $verifyAction, $verifyPurpose, $policies);
$consume($service, $sharedIp, $verifyAction, $verifyPurpose, $policies);
$changedAccountDecision = $consume(
    $service,
    AuthenticationRateLimitContext::forUser(2, '192.0.2.13'),
    $verifyAction,
    $verifyPurpose,
    $policies
);
assertRateLimitSame(
    true,
    $changedAccountDecision->allowed,
    'A different account on the same IP must remain allowed below the higher IP threshold.'
);
assertRateLimitTrue(
    in_array(AuthenticationRateLimitBucketDimension::IP_ADDRESS->value, $changedAccountDecision->blockedDimensions, true) === false,
    'The higher IP threshold must avoid prematurely blocking another account on the same shared IP.'
);

$clearRows();
$rawEmail = '  Alice.Raw@Example.Test  ';
$rawIp = '2001:0db8:0:0:0:0:0:2';
$rawSession = 'raw-session-identifier';
$rawChallenge = 'raw-challenge-identifier';
$emailContext = AuthenticationRateLimitContext::forEmail($rawEmail, $rawIp, $rawSession, $rawChallenge);
$consume($service, $emailContext, $verifyAction, $verifyPurpose, $policies);
$persistedRows = $pdo->query('SELECT * FROM authentication_rate_limit')->fetchAll(PDO::FETCH_ASSOC);
$persistedText = strtolower((string) json_encode($persistedRows, JSON_THROW_ON_ERROR));
foreach ([
    trim(strtolower($rawEmail)),
    strtolower($rawIp),
    '2001:db8::2',
    strtolower($rawSession),
    strtolower($rawChallenge),
] as $rawIdentifier) {
    assertRateLimitTrue(
        str_contains($persistedText, $rawIdentifier) === false,
        'Raw rate-limit identifiers must never be persisted.'
    );
}
foreach ($persistedRows as $persistedRow) {
    assertRateLimitTrue(
        preg_match('/^[a-f0-9]{64}$/', (string) $persistedRow['bucket_hash']) === 1,
        'Every persisted bucket identifier must be an HMAC-SHA-256 digest.'
    );
}

$clearRows();
$domainContext = AuthenticationRateLimitContext::forUser(1, '192.0.2.14');
$consume($service, $domainContext, $verifyAction, $verifyPurpose, $policies);
$consume(
    $service,
    $domainContext,
    AuthenticationRateLimitAction::RECOVERY_CODE_GENERATE,
    $verifyPurpose,
    $policies
);
$consume(
    $service,
    $domainContext,
    $verifyAction,
    AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY,
    $policies
);
assertRateLimitSame(
    6,
    (int) $pdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn(),
    'Changing action or purpose must create independently domain-separated account/IP buckets.'
);
assertRateLimitSame(
    3,
    (int) $pdo->query('SELECT COUNT(DISTINCT purpose) FROM authentication_rate_limit')->fetchColumn(),
    'Only safe action/purpose labels may distinguish persisted policies.'
);

$clearRows();
$resetContext = AuthenticationRateLimitContext::forUser(
    1,
    '192.0.2.15',
    'session-reset',
    'challenge-reset'
);
$consume($service, $resetContext, $verifyAction, $verifyPurpose, $policies);
$service->resetAfterSuccessfulCredentialVerification($resetContext, $verifyPurpose);
assertRateLimitSame(
    1,
    (int) $pdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn(),
    'Credential success must clear account/session/challenge buckets but preserve shared IP history.'
);
assertRateLimitSame(
    1,
    (int) $pdo->query('SELECT attempt_count FROM authentication_rate_limit')->fetchColumn(),
    'The retained IP bucket must preserve its attempt history.'
);

$clearRows();
$generationContext = AuthenticationRateLimitContext::forUser(1, '192.0.2.16');
$consume(
    $service,
    $generationContext,
    AuthenticationRateLimitAction::RECOVERY_CODE_GENERATE,
    AuthenticationRateLimitPurpose::REPLACE_RECOVERY_CODES,
    $policies
);
assertRateLimitSame(
    2,
    (int) $pdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn(),
    'Successful generation must leave both account and IP attempts recorded.'
);

$clearRows();
$failingRepository = new FailingSecondBucketRateLimitRepository($connection);
$failingService = new AuthenticationRateLimitService($connection, $failingRepository, $pepper, $clock);
$atomicFailureObserved = false;
try {
    $consume(
        $failingService,
        AuthenticationRateLimitContext::forUser(1, '192.0.2.17'),
        $verifyAction,
        $verifyPurpose,
        $policies
    );
} catch (RuntimeException $exception) {
    $atomicFailureObserved = $exception->getMessage() === 'simulated second-bucket persistence failure';
}
assertRateLimitTrue($atomicFailureObserved, 'The simulated second-bucket persistence failure must propagate.');
assertRateLimitSame(
    0,
    (int) $pdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn(),
    'A failure while recording one mandatory bucket must roll back every bucket.'
);

$clearRows();
$raceRepository = new UniqueRaceAuthenticationRateLimitRepository($connection);
$raceService = new AuthenticationRateLimitService($connection, $raceRepository, $pepper, $clock);
$raceDecision = $consume(
    $raceService,
    AuthenticationRateLimitContext::forUser(1, '192.0.2.18'),
    $verifyAction,
    $verifyPurpose,
    $policies
);
assertRateLimitSame(true, $raceDecision->allowed, 'A concurrent first-insert race must be retried safely.');
assertRateLimitSame(3, $raceRepository->insertCalls, 'The full multi-bucket attempt must be retried after the race.');
assertRateLimitSame(
    2,
    (int) $pdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn(),
    'A retried first-insert race must still persist every mandatory bucket.'
);

$clearRows();
$integrityRepository = new NonDuplicateIntegrityFailureRateLimitRepository($connection);
$integrityService = new AuthenticationRateLimitService($connection, $integrityRepository, $pepper, $clock);
$integrityFailureObserved = false;
try {
    $consume(
        $integrityService,
        AuthenticationRateLimitContext::forUser(1, '192.0.2.19'),
        $verifyAction,
        $verifyPurpose,
        $policies
    );
} catch (PDOException $exception) {
    $integrityFailureObserved = (int) ($exception->errorInfo[1] ?? 0) === 1452;
}
assertRateLimitTrue($integrityFailureObserved, 'Non-duplicate integrity failures must propagate immediately.');
assertRateLimitSame(
    1,
    $integrityRepository->insertCalls,
    'A foreign-key or other non-duplicate integrity failure must never be retried.'
);

foreach ([
    ['sql_state' => '40001', 'driver_code' => 1213, 'label' => 'deadlock'],
    ['sql_state' => 'HY000', 'driver_code' => 1205, 'label' => 'lock-wait timeout'],
] as $contentionFailure) {
    $clearRows();
    $contentionRepository = new RetryableContentionRateLimitRepository(
        $connection,
        $contentionFailure['sql_state'],
        $contentionFailure['driver_code']
    );
    $contentionService = new AuthenticationRateLimitService(
        $connection,
        $contentionRepository,
        $pepper,
        $clock
    );
    $contentionDecision = $consume(
        $contentionService,
        AuthenticationRateLimitContext::forUser(1, '192.0.2.20'),
        $verifyAction,
        $verifyPurpose,
        $policies
    );
    assertRateLimitSame(
        true,
        $contentionDecision->allowed,
        'A MariaDB ' . $contentionFailure['label'] . ' should receive a bounded transaction retry.'
    );
    assertRateLimitSame(
        3,
        $contentionRepository->insertCalls,
        'The full multi-bucket attempt must restart after a MariaDB ' . $contentionFailure['label'] . '.'
    );
}

$clearRows();
$persistentDeadlockRepository = new RetryableContentionRateLimitRepository(
    $connection,
    '40001',
    1213,
    PHP_INT_MAX
);
$persistentDeadlockService = new AuthenticationRateLimitService(
    $connection,
    $persistentDeadlockRepository,
    $pepper,
    $clock
);
$persistentDeadlockObserved = false;
try {
    $consume(
        $persistentDeadlockService,
        AuthenticationRateLimitContext::forUser(1, '192.0.2.21'),
        $verifyAction,
        $verifyPurpose,
        $policies
    );
} catch (PDOException $exception) {
    $persistentDeadlockObserved = (int) ($exception->errorInfo[1] ?? 0) === 1213;
}
assertRateLimitTrue($persistentDeadlockObserved, 'Persistent transaction contention must fail closed.');
assertRateLimitSame(
    3,
    $persistentDeadlockRepository->insertCalls,
    'Retryable transaction contention must stop after three total attempts.'
);
assertRateLimitSame(
    0,
    (int) $pdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn(),
    'An exhausted contention retry must leave no partial bucket writes.'
);

$transactionMethod = new ReflectionMethod(Connection::class, 'transaction');
assertRateLimitTrue($transactionMethod->isPublic(), 'The callback transaction API must remain public.');
foreach (['beginTransaction', 'commit', 'rollBack', 'inTransaction'] as $methodName) {
    $method = new ReflectionMethod(Connection::class, $methodName);
    assertRateLimitTrue($method->isPrivate(), $methodName . ' must remain an internal transaction detail.');
}

$clearRows();

$userOne = AuthenticationRateLimitContext::forUser(
    1,
    '192.0.2.50',
    'session-user-one'
);

$policies = testPolicies(
    accountMaximum: 2,
    ipMaximum: 10,
    sessionMaximum: 5,
    challengeMaximum: 2
);

$consume($service, $userOne, $verifyAction, $verifyPurpose, $policies);
$consume($service, $userOne, $verifyAction, $verifyPurpose, $policies);

$blockedUserOne = $consume(
    $service,
    $userOne,
    $verifyAction,
    $verifyPurpose,
    $policies
);

assertRateLimitSame(
    false,
    $blockedUserOne->allowed,
    'The exhausted account must be blocked.'
);

$userTwoDecision = $consume(
    $service,
    AuthenticationRateLimitContext::forUser(
        2,
        '192.0.2.50',
        'session-user-two'
    ),
    $verifyAction,
    $verifyPurpose,
    $policies
);

assertRateLimitSame(
    true,
    $userTwoDecision->allowed,
    'Another account on the same IP must not be blocked by the stricter account policy.'
);

$clearRows();

$consume(
    $service,
    AuthenticationRateLimitContext::forUser(1, '192.0.2.60', 'session-one'),
    $verifyAction,
    $verifyPurpose,
    $policies
);

$consume(
    $service,
    AuthenticationRateLimitContext::forUser(1, '192.0.2.60', 'session-two'),
    $verifyAction,
    $verifyPurpose,
    $policies
);

$decision = $consume(
    $service,
    AuthenticationRateLimitContext::forUser(1, '192.0.2.61', 'session-three'),
    $verifyAction,
    $verifyPurpose,
    $policies
);

assertRateLimitSame(
    false,
    $decision->allowed,
    'Changing session and IP must not bypass the account bucket.'
);

echo "Authentication rate-limit service tests passed.\n";
