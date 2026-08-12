<?php

declare(strict_types=1);

use src\Business\AuthenticationRateLimitAction;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\AuthenticationRateLimitService;
use src\Business\EmailTOTPPurpose;
use src\Business\EmailTOTPService;
use src\Business\RateLimitDecision;
use src\Business\TOTPService;
use src\Data\Repository\EmailTOTPRepository;

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 300;
const AUTHENTICATOR_TOTP_DIGITS = 6;
const AUTHENTICATOR_TOTP_PERIOD = 30;

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file) === true) { require_once $file; }
});

final class IntentRepository extends EmailTOTPRepository
{
    public array $records = [];
    private int $nextId = 1;
    public function __construct() {}
    public function storeTOTP(int $userId, EmailTOTPPurpose $purpose, string $secret, string $expiresAt): int
    {
        $id = $this->nextId++;
        $this->records[$id] = (object) ['id'=>$id, 'user_id'=>$userId, 'purpose'=>$purpose->value, 'totp_secret'=>$secret, 'expires_at'=>$expiresAt];
        return $id;
    }
    public function findAllValidTOTPs(int $userId, EmailTOTPPurpose $purpose): array
    {
        return array_values(array_filter($this->records, static fn(object $record): bool =>
            (int) $record->user_id === $userId && (string) $record->purpose === $purpose->value
            && strtotime((string) $record->expires_at) >= time()));
    }
    public function consumeTOTP(int $id, int $userId, EmailTOTPPurpose $purpose): bool
    {
        $record = $this->records[$id] ?? null;
        if ($record === null || (int) $record->user_id !== $userId || (string) $record->purpose !== $purpose->value) { return false; }
        unset($this->records[$id]);
        return true;
    }
    public function deleteTOTP(int $id): void { unset($this->records[$id]); }
}
final class IntentTOTP extends TOTPService
{
    private int $id = 0;
    public function generateSecret(int $digits=TOTP_DIGITS, int $period=TOTP_PERIOD): string { return 's' . ++$this->id; }
    public function generateTOTP(?string $secret=null, ?int $digits=AUTHENTICATOR_TOTP_DIGITS, ?int $period=AUTHENTICATOR_TOTP_PERIOD): string { return 'code-' . $secret; }
    public function verifyTOTP(string $secret, string $code, int $digits=TOTP_DIGITS, int $period=TOTP_PERIOD): bool { return $code === 'code-' . $secret; }
}
final class IntentLimiter extends AuthenticationRateLimitService
{
    public array $attempts = [];
    public function __construct() {}
    public function consumeAttempt(AuthenticationRateLimitContext $context, AuthenticationRateLimitAction $action, AuthenticationRateLimitPurpose $purpose, array $policies): RateLimitDecision
    {
        $key = $action->value . '.' . $purpose->value;
        $this->attempts[$key] = ($this->attempts[$key] ?? 0) + 1;
        return new RateLimitDecision(true, 10, null);
    }
    public function resetAfterSuccessfulVerification(AuthenticationRateLimitContext $context, AuthenticationRateLimitAction $action, AuthenticationRateLimitPurpose $purpose): void {}
}
function same(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) { throw new RuntimeException($message); } }

$repository = new IntentRepository();
$limiter = new IntentLimiter();
$service = new EmailTOTPService(new IntentTOTP(), $repository, $limiter);
$a = AuthenticationRateLimitContext::forUser(42, '192.0.2.1', 'a');
$b = AuthenticationRateLimitContext::forUser(42, '198.51.100.2', 'b');
$login1 = $service->issue(42, EmailTOTPPurpose::LOGIN, $a);
$login2 = $service->issue(42, EmailTOTPPurpose::LOGIN, $a);
$recovery = $service->issue(42, EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY, $a);
$enrollment = $service->issue(42, EmailTOTPPurpose::AUTHENTICATOR_ENROLLMENT, $a);
$repository->records[99] = (object) ['id'=>99, 'user_id'=>42, 'purpose'=>null, 'totp_secret'=>'legacy', 'expires_at'=>date('Y-m-d H:i:s', time() + 60)];
same(false, $service->verify(42, EmailTOTPPurpose::LOGIN, 'code-legacy', $b), 'Legacy purpose-less challenge authenticated.');
same(false, $service->verify(42, EmailTOTPPurpose::LOGIN, $recovery->code, $b), 'Recovery crossed into login.');
same(false, $service->verify(42, EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY, $login1->code, $b), 'Login crossed into recovery.');
same(false, $service->verify(42, EmailTOTPPurpose::LOGIN, $enrollment->code, $b), 'Enrollment crossed into login.');
same(true, $service->verify(42, EmailTOTPPurpose::LOGIN, $login1->code, $b), 'Cross-session login failed.');
same(false, $service->verify(42, EmailTOTPPurpose::LOGIN, $login1->code, $b), 'Consumed challenge replayed.');
same(true, $service->verify(42, EmailTOTPPurpose::LOGIN, $login2->code, $b), 'Coexisting challenge was invalidated.');
same(false, $repository->consumeTOTP($login2->id, 42, EmailTOTPPurpose::LOGIN), 'Atomic consumption allowed a second winner.');
$expired = $service->issue(42, EmailTOTPPurpose::LOGIN, $a);
$repository->records[$expired->id]->expires_at = date('Y-m-d H:i:s', time() - 1);
same(false, $service->verify(42, EmailTOTPPurpose::LOGIN, $expired->code, $b), 'Expired challenge succeeded.');
$service->cancelIssued($enrollment);
same(false, isset($repository->records[$enrollment->id]), 'Failed-delivery cancellation failed.');
same(3, $limiter->attempts['email_totp_issue.email_totp_login'], 'Issuance did not use a purpose-separated bucket.');
fwrite(STDOUT, "Email TOTP service tests passed.\n");

// Durable limiter regression coverage uses the real SQLite-backed implementation.
$ratePdo = new PDO('sqlite::memory:');
$ratePdo->exec('CREATE TABLE authentication_rate_limit (
    bucket_hash TEXT NOT NULL,
    purpose TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    window_started_at TEXT NOT NULL,
    blocked_until TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket_hash, purpose)
)');
$rateConnection = new \src\Data\Connection($ratePdo);
$rateRepository = new \src\Data\Repository\AuthenticationRateLimitRepository($rateConnection);
$clockNow = new DateTimeImmutable('2035-01-01 00:00:00');
$clock = static function () use (&$clockNow): DateTimeImmutable { return $clockNow; };
$realLimiter = new AuthenticationRateLimitService(
    $rateConnection,
    $rateRepository,
    str_repeat('email-totp-test-pepper-', 2),
    $clock
);
$ratePolicies = [
    \src\Business\AuthenticationRateLimitBucketDimension::ACCOUNT->value => new \src\Business\RateLimitPolicy(2, 100, 10),
    \src\Business\AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new \src\Business\RateLimitPolicy(100, 100, 10),
    \src\Business\AuthenticationRateLimitBucketDimension::SESSION->value => new \src\Business\RateLimitPolicy(100, 100, 10),
    \src\Business\AuthenticationRateLimitBucketDimension::CHALLENGE->value => new \src\Business\RateLimitPolicy(100, 100, 10),
];
$loginPurpose = EmailTOTPPurpose::LOGIN->rateLimitPurpose();
$consumeReal = static function (
    AuthenticationRateLimitContext $context,
    AuthenticationRateLimitAction $action,
    AuthenticationRateLimitPurpose $purpose
) use ($realLimiter, $ratePolicies): RateLimitDecision {
    return $realLimiter->consumeAttempt($context, $action, $purpose, $ratePolicies);
};
$clearRateLimits = static function () use ($ratePdo): void { $ratePdo->exec('DELETE FROM authentication_rate_limit'); };

$consumeReal(AuthenticationRateLimitContext::forUser(70, '192.0.2.1', 'one'), AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE, $loginPurpose);
$consumeReal(AuthenticationRateLimitContext::forUser(70, '192.0.2.2', 'two'), AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE, $loginPurpose);
$rotatedIssue = $consumeReal(AuthenticationRateLimitContext::forUser(70, '192.0.2.3', 'three'), AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE, $loginPurpose);
same(false, $rotatedIssue->allowed, 'IP and session rotation bypassed the issuance account bucket.');

$clearRateLimits();
$consumeReal(AuthenticationRateLimitContext::forUser(71, '192.0.2.4', 'one'), AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
$consumeReal(AuthenticationRateLimitContext::forUser(71, '192.0.2.5', 'two'), AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
$rotatedVerify = $consumeReal(AuthenticationRateLimitContext::forUser(71, '192.0.2.6', 'three'), AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
same(false, $rotatedVerify->allowed, 'IP and session rotation bypassed the verification account bucket.');

$clearRateLimits();
$separated = AuthenticationRateLimitContext::forUser(72, '192.0.2.7');
$consumeReal($separated, AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE, $loginPurpose);
$consumeReal($separated, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
$consumeReal($separated, AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE, EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY->rateLimitPurpose());
same(3, (int) $ratePdo->query('SELECT COUNT(DISTINCT purpose) FROM authentication_rate_limit')->fetchColumn(), 'Actions or purposes shared persisted buckets.');

$clearRateLimits();
$progressiveContext = AuthenticationRateLimitContext::forUser(73, '192.0.2.8');
$consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
$consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
$firstBlock = $consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
same(10, $firstBlock->retryAfterSeconds, 'First threshold breach did not use the finite initial block.');
$blockedUntil = (string) $ratePdo->query("SELECT blocked_until FROM authentication_rate_limit WHERE blocked_until IS NOT NULL")->fetchColumn();
$consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
same($blockedUntil, (string) $ratePdo->query("SELECT blocked_until FROM authentication_rate_limit WHERE blocked_until IS NOT NULL")->fetchColumn(), 'An active-block attempt extended the block.');
$clockNow = $clockNow->modify('+11 seconds');
same(true, $consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose)->allowed, 'Request did not proceed after block expiry.');
$secondBlock = $consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
same(true, ($secondBlock->retryAfterSeconds ?? 0) > 10, 'Continued abuse did not increase backoff.');
same(true, ($secondBlock->retryAfterSeconds ?? 0) <= 160, 'Progressive backoff exceeded its policy cap.');
$clockNow = $clockNow->modify('+' . (($secondBlock->retryAfterSeconds ?? 0) + 1) . ' seconds');
same(true, $consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose)->allowed, 'Capped-backoff preparation did not proceed after expiry.');
$cappedBlock = $consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose);
same(160, $cappedBlock->retryAfterSeconds, 'Progressive backoff did not respect the finite policy cap.');
$clockNow = $clockNow->modify('+161 seconds');
same(true, $consumeReal($progressiveContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose)->allowed, 'Window expiry did not reset pressure.');

$clearRateLimits();
$longBlockPolicies = [
    \src\Business\AuthenticationRateLimitBucketDimension::ACCOUNT->value => new \src\Business\RateLimitPolicy(2, 60, 120),
    \src\Business\AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new \src\Business\RateLimitPolicy(10, 60, 120),
];
$longBlockContext = AuthenticationRateLimitContext::forUser(75, '192.0.2.10');
$realLimiter->consumeAttempt($longBlockContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose, $longBlockPolicies);
$realLimiter->consumeAttempt($longBlockContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose, $longBlockPolicies);
$longBlock = $realLimiter->consumeAttempt($longBlockContext, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $loginPurpose, $longBlockPolicies);
same(120, $longBlock->retryAfterSeconds, 'Initial cooldown was incorrectly capped by the counting window.');

$clearRateLimits();
$resetContext = AuthenticationRateLimitContext::forUser(74, '192.0.2.9', 'session', 'challenge');
$resetRepository = new IntentRepository();
$resetService = new EmailTOTPService(new IntentTOTP(), $resetRepository, $realLimiter);
$resetIssued = $resetService->issue(74, EmailTOTPPurpose::LOGIN, $resetContext);
same(true, $resetService->verify(74, EmailTOTPPurpose::LOGIN, $resetIssued->code, $resetContext), 'Email TOTP success failed during reset coverage.');
$verifyPersistencePurpose = AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY->value . '.' . $loginPurpose->value;
$verifyRows = $ratePdo->query("SELECT * FROM authentication_rate_limit WHERE purpose = '" . $verifyPersistencePurpose . "'")->fetchAll(PDO::FETCH_ASSOC);
same(1, count($verifyRows), 'Email TOTP success did not reset account/session/challenge pressure.');
same(1, (int) $verifyRows[0]['attempt_count'], 'Email TOTP success unexpectedly cleared IP history.');

// Independent persistence consumers compete for the same exact challenge.
$atomicPath = tempnam(sys_get_temp_dir(), 'email-totp-atomic-');
if ($atomicPath === false) { throw new RuntimeException('Unable to create atomic-consumption database.'); }
$atomicPdoOne = new PDO('sqlite:' . $atomicPath);
$atomicPdoOne->exec('CREATE TABLE email_totp (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, purpose TEXT NULL, totp_secret TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$atomicPdoTwo = new PDO('sqlite:' . $atomicPath);
$atomicRepositoryOne = new EmailTOTPRepository(new \src\Data\Connection($atomicPdoOne));
$atomicRepositoryTwo = new EmailTOTPRepository(new \src\Data\Connection($atomicPdoTwo));
$atomicId = $atomicRepositoryOne->storeTOTP(80, EmailTOTPPurpose::LOGIN, 'atomic-secret', date('Y-m-d H:i:s', time() + 60));
$firstConsumer = $atomicRepositoryOne->consumeTOTP($atomicId, 80, EmailTOTPPurpose::LOGIN);
$secondConsumer = $atomicRepositoryTwo->consumeTOTP($atomicId, 80, EmailTOTPPurpose::LOGIN);
same(1, (int) $firstConsumer + (int) $secondConsumer, 'Independent consumers did not produce exactly one winner.');
unlink($atomicPath);

// Pin the existing user-facing Email TOTP content without modifying it.
$emailServiceSource = file_get_contents(__DIR__ . '/../src/Business/EmailService.php');
if (is_string($emailServiceSource) === false) { throw new RuntimeException('Unable to inspect EmailService content.'); }
same(true, str_contains($emailServiceSource, "APP_NAME . ' - Your OTP code has arrived'"), 'Email TOTP subject changed.');
same(true, str_contains($emailServiceSource, 'single time use OTP code: <strong>%2\\$d</strong>'), 'Email TOTP body changed.');

fwrite(STDOUT, "Durable Email TOTP security tests passed.\n");
