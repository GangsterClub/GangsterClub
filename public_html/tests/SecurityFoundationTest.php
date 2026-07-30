<?php

declare(strict_types=1);

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitAction;
use src\Business\AuthenticationRateLimitBucketDimension;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\AuthenticationRateLimitService;
use src\Business\RateLimitPolicy;
use src\Business\RecoveryCodeCodec;
use src\Business\RecoveryCodeConsumptionResult;
use src\Business\RecoveryCodeService;
use src\Business\SecurityAuditService;
use src\Data\Connection;
use src\Data\Repository\AuthenticationChallengeRepository;
use src\Data\Repository\AuthenticationRateLimitRepository;
use src\Data\Repository\RecoveryCodeRepository;
use src\Data\Repository\SecurityAuditEventRepository;

require_once __DIR__ . '/../vendor/autoload.php';

function assertSecuritySame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertSecurityTrue(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo);
$schema = [
    'CREATE TABLE user (id INTEGER PRIMARY KEY, email TEXT NOT NULL)',
    'CREATE TABLE user_authenticator_totp (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        secret TEXT NOT NULL,
        generation INTEGER NOT NULL DEFAULT 1
    )',
    'CREATE TABLE authentication_challenge (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL,
        purpose TEXT NOT NULL,
        state TEXT NOT NULL,
        session_binding_hash TEXT NOT NULL,
        baseline_authenticator_generation INTEGER NULL,
        baseline_recovery_code_set_id INTEGER NULL,
        reauthenticated_at TEXT NULL,
        expires_at TEXT NOT NULL,
        completed_at TEXT NULL,
        cancelled_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )',
    'CREATE TABLE authentication_rate_limit (
        bucket_hash TEXT NOT NULL,
        purpose TEXT NOT NULL,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        blocked_until TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (bucket_hash, purpose)
    )',
    'CREATE TABLE security_audit_event (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        event_type TEXT NOT NULL,
        context_json TEXT NULL,
        created_at TEXT NOT NULL
    )',
    'CREATE TABLE recovery_code_set (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        purpose TEXT NOT NULL,
        status TEXT NOT NULL,
        authenticator_generation INTEGER NOT NULL DEFAULT 1,
        authentication_challenge_id INTEGER NULL,
        replaces_recovery_code_set_id INTEGER NULL,
        displayed_at TEXT NULL,
        acknowledged_at TEXT NULL,
        invalidated_at TEXT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )',
    'CREATE TABLE recovery_code (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recovery_code_set_id INTEGER NOT NULL,
        code_hash TEXT NOT NULL UNIQUE,
        used_at TEXT NULL,
        created_at TEXT NOT NULL
    )',
    'CREATE TABLE user_recovery_code_state (
        user_id INTEGER PRIMARY KEY,
        active_recovery_code_set_id INTEGER NULL UNIQUE,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )',
];
foreach ($schema as $sql) {
    $pdo->exec($sql);
}
$pdo->exec("INSERT INTO user (id, email) VALUES (42, 'alice@example.test')");
$pdo->exec("INSERT INTO user_authenticator_totp (user_id, secret, generation) VALUES (42, 'active-secret', 1)");

$now = new DateTimeImmutable('2030-01-02 03:04:05');
$clockNow = $now;
$clock = static function () use (&$clockNow): DateTimeImmutable {
    return $clockNow;
};
$challengePepper = str_repeat('challenge-secret-', 3);
$challengeRepository = new AuthenticationChallengeRepository($connection);
$challengeService = new AuthenticationChallengeService(
    $connection,
    $challengeRepository,
    $challengePepper,
    $clock
);

$challenge = $challengeService->start(
    42,
    AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT,
    'session-a'
);
assertSecuritySame(64, strlen($challenge['token']), 'Challenge tokens should contain 256 random bits encoded as hex.');
assertSecuritySame(
    '2030-01-02 03:19:05',
    $challenge['expires_at']->format('Y-m-d H:i:s'),
    'Enrollment challenges should have a fixed fifteen-minute lifetime.'
);
$storedChallenge = $pdo->query('SELECT * FROM authentication_challenge')->fetch();
assertSecurityTrue(
    $storedChallenge->token_hash !== $challenge['token'],
    'The raw challenge token must not be persisted.'
);
assertSecuritySame(
    null,
    $challengeService->getActive(
        $challenge['token'],
        'wrong-session',
        AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT
    ),
    'A challenge must remain bound to its originating session.'
);

$invalidTransitionRejected = false;
try {
    $challengeService->transition(
        $challenge['token'],
        'session-a',
        AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT,
        'email_verification_pending',
        'authenticator_verified'
    );
} catch (DomainException) {
    $invalidTransitionRejected = true;
}
assertSecurityTrue($invalidTransitionRejected, 'Challenge steps must not be skipped.');
$advanced = $challengeService->transition(
    $challenge['token'],
    'session-a',
    AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT,
    'email_verification_pending',
    'email_verified'
);
assertSecuritySame('email_verified', $advanced?->state, 'A valid challenge transition should advance once.');
assertSecuritySame(
    null,
    $challengeService->transition(
        $challenge['token'],
        'session-a',
        AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT,
        'email_verification_pending',
        'email_verified'
    ),
    'A completed transition must not be replayable.'
);
assertSecuritySame(
    '2030-01-02 03:19:05',
    (string) $pdo->query('SELECT expires_at FROM authentication_challenge')->fetchColumn(),
    'Challenge transitions must not extend expiry.'
);

$reauth = $challengeService->start(
    42,
    AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES,
    'session-a'
);
assertSecuritySame(
    '2030-01-02 03:19:05',
    $reauth['expires_at']->format('Y-m-d H:i:s'),
    'The recovery-code ceremony should retain its independent fifteen-minute challenge lifetime.'
);
$verifiedReauth = $challengeService->transition(
    $reauth['token'],
    'session-a',
    AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES,
    'fresh_reauthentication_pending',
    'freshly_reauthenticated'
);
assertSecurityTrue(
    $verifiedReauth !== null
    && $challengeService->isFreshReauthentication(
        $verifiedReauth,
        AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES
    ),
    'Verified reauthentication should be fresh and purpose-bound.'
);
assertSecurityTrue(
    $challengeService->isFreshReauthentication(
        $verifiedReauth,
        AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES
    ) === false,
    'Reauthentication grants must not authorize a different purpose.'
);
$clockNow = $now->modify('+301 seconds');
assertSecurityTrue(
    $challengeService->isFreshReauthentication(
        $verifiedReauth,
        AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES
    ) === false,
    'Purpose-bound reauthentication should stop being fresh after five minutes.'
);
$clockNow = $now;

$ratePepper = str_repeat('rate-limit-secret-', 3);
$rateRepository = new AuthenticationRateLimitRepository($connection);
$rateService = new AuthenticationRateLimitService($connection, $rateRepository, $ratePepper, $clock);
$policies = [
    AuthenticationRateLimitBucketDimension::ACCOUNT->value =>
        new RateLimitPolicy(2, 60, 120),

    AuthenticationRateLimitBucketDimension::IP_ADDRESS->value =>
        new RateLimitPolicy(10, 60, 120),

    AuthenticationRateLimitBucketDimension::SESSION->value =>
        new RateLimitPolicy(4, 60, 120),

    AuthenticationRateLimitBucketDimension::CHALLENGE->value =>
        new RateLimitPolicy(2, 60, 120),
];
$basicRateLimitContext = AuthenticationRateLimitContext::forUser(
    42,
    '192.0.2.42',
    'session-a',
    'challenge-a'
);
$firstPermit = $rateService->consumeAttempt(
    $basicRateLimitContext,
    AuthenticationRateLimitAction::RECOVERY_CODE_VERIFY,
    AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY,
    $policies
);
$secondPermit = $rateService->consumeAttempt(
    $basicRateLimitContext,
    AuthenticationRateLimitAction::RECOVERY_CODE_VERIFY,
    AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY,
    $policies
);
$blockedPermit = $rateService->consumeAttempt(
    $basicRateLimitContext,
    AuthenticationRateLimitAction::RECOVERY_CODE_VERIFY,
    AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY,
    $policies
);
assertSecuritySame(true, $firstPermit->allowed, 'The first rate-limit permit should be allowed.');
assertSecuritySame(1, $firstPermit->remainingAttempts, 'The first permit should report one remaining attempt.');
assertSecuritySame(true, $secondPermit->allowed, 'The second permit should be allowed.');
assertSecuritySame(false, $blockedPermit->allowed, 'Attempts beyond the purpose policy should be blocked.');
assertSecuritySame(120, $blockedPermit->retryAfterSeconds, 'A blocked bucket should report its cooldown.');
assertSecuritySame(
    [
        AuthenticationRateLimitBucketDimension::ACCOUNT->value,
        AuthenticationRateLimitBucketDimension::CHALLENGE->value,
    ],
    $blockedPermit->blockedDimensions,
    'Only exhausted dimensions should be reported as blocked.'
);

$auditRepository = new SecurityAuditEventRepository($connection);
$auditService = new SecurityAuditService($auditRepository, $clock);
$unsafeAuditRejected = false;
try {
    $auditService->record('recovery_code.used', 42, ['code' => 'must-not-be-recorded']);
} catch (InvalidArgumentException) {
    $unsafeAuditRejected = true;
}
assertSecurityTrue($unsafeAuditRejected, 'Audit context must reject unapproved credential-like fields.');
$unsafeAuditValueRejected = false;
try {
    $auditService->record('recovery_code.used', 42, ['reason' => 'ABCDE-ABCDE-ABCDE-ABCDE']);
} catch (InvalidArgumentException) {
    $unsafeAuditValueRejected = true;
}
assertSecurityTrue(
    $unsafeAuditValueRejected,
    'Audit context labels must reject values that could contain credential material.'
);

$recoveryPepper = str_repeat('recovery-code-secret-', 3);
$codec = new RecoveryCodeCodec($recoveryPepper);
$generatedFormats = [];
for ($index = 0; $index < 100; ++$index) {
    $generatedCode = $codec->generate();
    assertSecurityTrue(
        preg_match('/^[0-9A-HJKMNP-TV-Z]{5}(?:-[0-9A-HJKMNP-TV-Z]{5}){3}$/', $generatedCode) === 1,
        'Generated recovery codes must use four Crockford Base32 groups.'
    );
    $generatedFormats[$generatedCode] = true;
}
assertSecuritySame(100, count($generatedFormats), 'Generated recovery codes should not collide in a sample.');
assertSecuritySame(100, RecoveryCodeCodec::ENTROPY_BITS, 'Recovery code entropy must remain explicit.');
assertSecuritySame(
    '00001111112222233333',
    $codec->normalize('O000I-lllll-22222-33333'),
    'Normalization should remove separators and map ambiguous Crockford characters.'
);
assertSecuritySame(null, $codec->normalize('00000-00000-00000-0000U'), 'Characters outside the alphabet must be rejected.');

$recoveryRepository = new RecoveryCodeRepository($connection);
$recoveryService = new RecoveryCodeService(
    $connection,
    $recoveryRepository,
    $codec,
    $rateService,
    $auditService,
    $clock
);
$mismatchedOwnerRejected = false;
try {
    $recoveryService->generatePendingSet(
        42,
        AuthenticationRateLimitPurpose::INITIAL_RECOVERY_CODES,
        AuthenticationRateLimitContext::forUser(43, '192.0.2.42')
    );
} catch (InvalidArgumentException) {
    $mismatchedOwnerRejected = true;
}
assertSecurityTrue(
    $mismatchedOwnerRejected,
    'Recovery-code generation must reject a rate-limit account that differs from the operation owner.'
);
$mismatchedVerificationOwnerRejected = false;
try {
    $recoveryService->consumeActiveCode(
        42,
        '00000-00000-00000-00000',
        AuthenticationRateLimitContext::forUser(43, '192.0.2.42'),
        AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN
    );
} catch (InvalidArgumentException) {
    $mismatchedVerificationOwnerRejected = true;
}
assertSecurityTrue(
    $mismatchedVerificationOwnerRejected,
    'Recovery-code verification must reject a rate-limit account that differs from the operation owner.'
);
$firstSet = $recoveryService->generatePendingSet(
    42,
    AuthenticationRateLimitPurpose::INITIAL_RECOVERY_CODES,
    AuthenticationRateLimitContext::forUser(42, '192.0.2.42', 'session-generate-initial'),
    1
);
assertSecuritySame(10, count($firstSet->codes), 'A recovery set must contain ten codes.');
assertSecuritySame(
    10,
    (int) $pdo->query('SELECT COUNT(*) FROM recovery_code')->fetchColumn(),
    'All recovery-code hashes should be persisted.'
);
$databaseText = implode(
    ' ',
    array_map(
        static fn (object $row): string => implode(' ', get_object_vars($row)),
        $pdo->query('SELECT * FROM recovery_code')->fetchAll()
    )
);
foreach ($firstSet->codes as $plaintextCode) {
    assertSecurityTrue(
        str_contains($databaseText, $plaintextCode) === false,
        'Recovery-code plaintext must never be persisted.'
    );
}
assertSecurityTrue(
    $recoveryService->activatePendingSet(42, $firstSet->setId),
    'An acknowledged initial set should activate.'
);

$firstConsumption = $recoveryService->consumeActiveCode(
    42,
    $firstSet->codes[0],
    AuthenticationRateLimitContext::forUser(42, '192.0.2.43', 'session-verify-a'),
    AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN
);
assertSecuritySame(
    RecoveryCodeConsumptionResult::STATUS_CONSUMED,
    $firstConsumption->status,
    'An unused active recovery code should be consumed.'
);
assertSecuritySame(9, $firstConsumption->remainingCount, 'Consumption should return the unused count.');
$reusedConsumption = $recoveryService->consumeActiveCode(
    42,
    $firstSet->codes[0],
    AuthenticationRateLimitContext::forUser(42, '192.0.2.43', 'session-verify-b'),
    AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN
);
assertSecuritySame(
    RecoveryCodeConsumptionResult::STATUS_INVALID,
    $reusedConsumption->status,
    'A consumed recovery code must not be reusable.'
);

$replacementSet = $recoveryService->generatePendingSet(
    42,
    AuthenticationRateLimitPurpose::REPLACE_RECOVERY_CODES,
    AuthenticationRateLimitContext::forUser(42, '192.0.2.42', 'session-generate-replacement'),
    1,
    null,
    $firstSet->setId
);
assertSecuritySame(
    $firstSet->setId,
    (int) $pdo->query('SELECT active_recovery_code_set_id FROM user_recovery_code_state')->fetchColumn(),
    'The old set must remain active while a replacement is pending.'
);
assertSecurityTrue(
    $recoveryService->activatePendingSet(42, $replacementSet->setId, $firstSet->setId),
    'Replacement acknowledgement should atomically swap active sets.'
);
assertSecuritySame(
    'invalidated',
    (string) $pdo->query('SELECT status FROM recovery_code_set WHERE id = ' . $firstSet->setId)->fetchColumn(),
    'The previous set should be invalidated during the swap.'
);
assertSecuritySame(
    $replacementSet->setId,
    (int) $pdo->query('SELECT active_recovery_code_set_id FROM user_recovery_code_state')->fetchColumn(),
    'The replacement set should become the only active set.'
);
assertSecuritySame(
    1,
    (int) $pdo->query("SELECT COUNT(*) FROM recovery_code_set WHERE status = 'active'")->fetchColumn(),
    'A user must never have two active recovery-code sets.'
);
$pdo->exec('UPDATE user_authenticator_totp SET generation = 2 WHERE user_id = 42');
$generationMismatch = $recoveryService->consumeActiveCode(
    42,
    $replacementSet->codes[0],
    AuthenticationRateLimitContext::forUser(42, '192.0.2.43', 'session-generation-mismatch'),
    AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN
);
assertSecuritySame(
    RecoveryCodeConsumptionResult::STATUS_INVALID,
    $generationMismatch->status,
    'A recovery set must be unusable when it does not match the active authenticator generation.'
);
$pdo->exec('UPDATE user_authenticator_totp SET generation = 1 WHERE user_id = 42');

$abandonedSet = $recoveryService->generatePendingSet(
    42,
    AuthenticationRateLimitPurpose::REPLACE_RECOVERY_CODES,
    AuthenticationRateLimitContext::forUser(42, '192.0.2.42', 'session-generate-abandoned'),
    1,
    null,
    $replacementSet->setId
);
assertSecurityTrue(
    $recoveryService->invalidatePendingSet(42, $abandonedSet->setId, 'unacknowledged_display'),
    'An inaccessible unacknowledged display should invalidate only its pending set.'
);
assertSecuritySame(
    $replacementSet->setId,
    (int) $pdo->query('SELECT active_recovery_code_set_id FROM user_recovery_code_state')->fetchColumn(),
    'Abandoning a pending replacement must preserve the old active set.'
);

$auditText = implode(
    ' ',
    array_map(
        static fn (object $row): string => (string) $row->context_json,
        $pdo->query('SELECT context_json FROM security_audit_event')->fetchAll()
    )
);
foreach (array_merge($firstSet->codes, $replacementSet->codes, $abandonedSet->codes) as $plaintextCode) {
    assertSecurityTrue(
        str_contains($auditText, $plaintextCode) === false,
        'Audit events must not contain recovery-code plaintext.'
    );
    $normalizedCode = $codec->normalize($plaintextCode);
    assertSecurityTrue(
        $normalizedCode !== null && str_contains($auditText, $codec->hash($normalizedCode)) === false,
        'Audit events must not contain recovery-code hashes.'
    );
}

$rollbackObserved = false;
try {
    $connection->transaction(function (Connection $transactionConnection): void {
        $transactionConnection->query("INSERT INTO user (id, email) VALUES (99, 'rollback@example.test')");
        throw new RuntimeException('force rollback');
    });
} catch (RuntimeException $exception) {
    $rollbackObserved = $exception->getMessage() === 'force rollback';
}
assertSecurityTrue($rollbackObserved, 'Transaction exceptions should propagate.');
assertSecuritySame(
    0,
    (int) $pdo->query('SELECT COUNT(*) FROM user WHERE id = 99')->fetchColumn(),
    'Transaction exceptions should roll back all writes.'
);

$nestedRollbackObserved = false;
try {
    $connection->transaction(function (Connection $outerConnection): void {
        $outerConnection->query("INSERT INTO user (id, email) VALUES (100, 'outer@example.test')");
        $outerConnection->transaction(function (Connection $innerConnection): void {
            $innerConnection->query("INSERT INTO user (id, email) VALUES (101, 'inner@example.test')");
            throw new RuntimeException('force nested rollback');
        });
    });
} catch (RuntimeException $exception) {
    $nestedRollbackObserved = $exception->getMessage() === 'force nested rollback';
}
assertSecurityTrue($nestedRollbackObserved, 'Nested transaction failures should propagate to the owner.');
assertSecuritySame(
    0,
    (int) $pdo->query('SELECT COUNT(*) FROM user WHERE id IN (100, 101)')->fetchColumn(),
    'Composed security services should share and roll back the outer transaction.'
);

fwrite(STDOUT, "Security foundation tests passed.\n");
