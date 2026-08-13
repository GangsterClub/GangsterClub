<?php

declare(strict_types=1);

use src\Business\AuthenticationRateLimitAction;
use src\Business\AuthenticationRateLimitBucketDimension;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\AuthenticationRateLimitService;
use src\Business\AuthenticatorTOTPPurpose;
use src\Business\AuthenticatorTOTPService;
use src\Business\RateLimitExceededException;
use src\Business\RateLimitPolicy;
use src\Business\TOTPService;
use src\Data\Connection;
use src\Data\Repository\AuthenticationRateLimitRepository;
use src\Data\Repository\UserAuthenticatorTOTPRepository;

const AUTHENTICATOR_TOTP_DIGITS = 6;
const AUTHENTICATOR_TOTP_PERIOD = 30;

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});

final class AuthenticatorTestTOTP extends TOTPService
{
    public function verifyTOTP(
        string $secret,
        string $code,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): bool {
        return $code === $secret . ':' . $digits . ':' . $period;
    }
}

function assertAuthenticatorTOTP(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function expectAuthenticatorLimit(callable $verification, string $message): void
{
    try {
        $verification();
    } catch (RateLimitExceededException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return array<string, RateLimitPolicy> */
function authenticatorTestPolicies(): array
{
    return [
        AuthenticationRateLimitBucketDimension::ACCOUNT->value => new RateLimitPolicy(5, 900, 60),
        AuthenticationRateLimitBucketDimension::CHALLENGE->value => new RateLimitPolicy(5, 900, 60),
        AuthenticationRateLimitBucketDimension::SESSION->value => new RateLimitPolicy(10, 900, 60),
        AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new RateLimitPolicy(50, 900, 60),
    ];
}

$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo);
$pdo->exec('CREATE TABLE authentication_rate_limit (
    bucket_hash TEXT NOT NULL, purpose TEXT NOT NULL, attempt_count INTEGER NOT NULL DEFAULT 0,
    window_started_at TEXT NOT NULL, blocked_until TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket_hash, purpose)
)');
$pdo->exec('CREATE TABLE user_authenticator_totp (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL UNIQUE, secret TEXT NOT NULL,
    digits INTEGER NOT NULL, period INTEGER NOT NULL, enabled_at TEXT NOT NULL,
    last_verified_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)');

$limiter = new AuthenticationRateLimitService(
    $connection,
    new AuthenticationRateLimitRepository($connection),
    str_repeat('authenticator-test-pepper-', 2)
);
$records = new UserAuthenticatorTOTPRepository($connection);
$service = new AuthenticatorTOTPService(new AuthenticatorTestTOTP(), $records, $limiter);
$records->upsertSecret(42, 'enrolled', 8, 45);

$context = AuthenticationRateLimitContext::forUser(42, '192.0.2.1', 'session-a', 'challenge-a');
assertAuthenticatorTOTP(
    $service->verify(42, AuthenticatorTOTPPurpose::LOGIN, 'invalid', $context) === false,
    'An invalid enrolled code must fail.'
);
assertAuthenticatorTOTP(
    $service->verify(42, AuthenticatorTOTPPurpose::LOGIN, 'enrolled:8:45', $context) === true,
    'A valid enrolled code must preserve stored digits and period.'
);
$verifiedRecord = $records->findByUserId(42);
assertAuthenticatorTOTP(
    $verifiedRecord !== false && $verifiedRecord->last_verified_at !== null,
    'Successful enrolled verification must update last_verified_at.'
);

$persistencePurpose = AuthenticationRateLimitAction::AUTHENTICATOR_TOTP_VERIFY->value
    . '.' . AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN->value;
$remainingBuckets = $pdo->query(
    "SELECT attempt_count FROM authentication_rate_limit WHERE purpose = '" . $persistencePurpose . "'"
)->fetchAll(PDO::FETCH_COLUMN);
assertAuthenticatorTOTP(
    $remainingBuckets === [2],
    'Success must reset account/session/challenge pressure while retaining both IP attempts.'
);

for ($attempt = 1; $attempt <= 5; ++$attempt) {
    $rotated = AuthenticationRateLimitContext::forUser(
        43,
        '198.51.100.' . $attempt,
        'rotated-session-' . $attempt
    );
    assertAuthenticatorTOTP(
        $service->verify(43, AuthenticatorTOTPPurpose::LOGIN, 'invalid', $rotated) === false,
        'Submitted invalid codes before the account limit must be evaluated.'
    );
}
expectAuthenticatorLimit(
    static fn(): bool => $service->verify(
        43,
        AuthenticatorTOTPPurpose::LOGIN,
        'invalid',
        AuthenticationRateLimitContext::forUser(43, '198.51.100.99', 'rotated-session-final')
    ),
    'Account limiting must survive IP and session rotation.'
);

$isolatedContext = AuthenticationRateLimitContext::forUser(44, '203.0.113.1', 'isolated');
for ($attempt = 1; $attempt <= 5; ++$attempt) {
    $service->verify(44, AuthenticatorTOTPPurpose::LOGIN, 'invalid', $isolatedContext);
}
assertAuthenticatorTOTP(
    $service->verifyPendingSecret(
        44,
        AuthenticatorTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
        'pending',
        'invalid',
        $isolatedContext
    ) === false,
    'Authenticator purposes must not share limiter pressure.'
);

for ($attempt = 1; $attempt <= 5; ++$attempt) {
    $limiter->consumeAttempt(
        AuthenticationRateLimitContext::forUser(45, '203.0.113.2'),
        AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY,
        AuthenticationRateLimitPurpose::AUTHENTICATOR_ENROLLMENT,
        authenticatorTestPolicies()
    );
}
assertAuthenticatorTOTP(
    $service->verifyPendingSecret(
        45,
        AuthenticatorTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
        'pending',
        'invalid',
        AuthenticationRateLimitContext::forUser(45, '203.0.113.2')
    ) === false,
    'Authenticator verification buckets must not share Email TOTP action pressure.'
);

$pendingEnrollment = AuthenticationRateLimitContext::forUser(46, '203.0.113.3');
for ($attempt = 1; $attempt <= 5; ++$attempt) {
    $service->verifyPendingSecret(
        46,
        AuthenticatorTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
        'pending',
        'invalid',
        $pendingEnrollment
    );
}
expectAuthenticatorLimit(
    static fn(): bool => $service->verifyPendingSecret(
        46,
        AuthenticatorTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
        'pending',
        'invalid',
        $pendingEnrollment
    ),
    'Pending enrollment verification must be throttled.'
);
assertAuthenticatorTOTP(
    $service->verifyPendingSecret(
        46,
        AuthenticatorTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
        'replacement',
        'invalid',
        $pendingEnrollment
    ) === false,
    'Lost-replacement pending verification must be isolated from enrollment pressure.'
);

$recoveryCodeContext = AuthenticationRateLimitContext::forUser(47, '203.0.113.4');
for ($attempt = 1; $attempt <= 5; ++$attempt) {
    $service->verify(
        47,
        AuthenticatorTOTPPurpose::INITIAL_RECOVERY_CODES,
        'invalid',
        $recoveryCodeContext
    );
}
expectAuthenticatorLimit(
    static fn(): bool => $service->verify(
        47,
        AuthenticatorTOTPPurpose::INITIAL_RECOVERY_CODES,
        'invalid',
        $recoveryCodeContext
    ),
    'Initial recovery-code reauthentication must enforce its account limit.'
);
assertAuthenticatorTOTP(
    $service->verify(
        47,
        AuthenticatorTOTPPurpose::REPLACE_RECOVERY_CODES,
        'invalid',
        $recoveryCodeContext
    ) === false,
    'Initial and replacement recovery-code reauthentication must use independent limiter namespaces.'
);

$lostReplacementContext = AuthenticationRateLimitContext::forUser(48, '203.0.113.5');
for ($attempt = 1; $attempt <= 5; ++$attempt) {
    $service->verifyPendingSecret(
        48,
        AuthenticatorTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
        'replacement',
        'invalid',
        $lostReplacementContext
    );
}
expectAuthenticatorLimit(
    static fn(): bool => $service->verifyPendingSecret(
        48,
        AuthenticatorTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
        'replacement',
        'invalid',
        $lostReplacementContext
    ),
    'Pending lost-authenticator replacement verification must enforce its account limit.'
);

assertAuthenticatorTOTP(
    AuthenticatorTOTPPurpose::AUTHENTICATOR_DISABLE->rateLimitPurpose()
        === AuthenticationRateLimitPurpose::AUTHENTICATOR_DISABLE,
    'Authenticator disable must map to its dedicated limiter purpose.'
);

fwrite(STDOUT, "AuthenticatorTOTPService tests passed.\n");
