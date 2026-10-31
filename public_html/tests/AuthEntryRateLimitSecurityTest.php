<?php

declare(strict_types=1);

use app\Service\AuthService;
use app\Service\CsrfService;
use app\Service\SessionService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitService;
use src\Business\AuthenticatorTOTPService;
use src\Business\AuthEntryOtpService;
use src\Business\AuthEntryService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RateLimitExceededException;
use src\Business\TOTPService;
use src\Business\UserService;
use src\Data\Connection;
use src\Data\Repository\AuthenticationRateLimitRepository;
use src\Data\Repository\EmailTOTPRepository;
use src\Entity\User;

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 300;
const AUTHENTICATOR_TOTP_DIGITS = 6;
const AUTHENTICATOR_TOTP_PERIOD = 30;

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file) === true) {
        require_once $file;
    }
});

final class RateLimitSecuritySession extends SessionService
{
    public array $values;

    public function __construct(string $ipAddress, string $identifier)
    {
        $this->values = ['_IPaddress' => $ipAddress, '_security_rate_limit_id' => $identifier];
    }

    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void {}
}

final class RateLimitSecurityUsers extends UserService
{
    /** @var array<string, User> */
    public array $users = [];

    public function __construct() {}

    public function getUserByEmail(string $email): ?User
    {
        return $this->users[strtolower(trim($email))] ?? null;
    }

    public function getUserByUsername(string $username): ?User { return null; }

    public function createUserByEmail(string $email, string $ipAddress, ?User $user = null): ?User
    {
        return $this->addUser($email, 900);
    }

    public function createUser(string $username, string $email, string $ipAddress): ?User
    {
        return $this->addUser($email, 901, $username);
    }

    public function addUser(string $email, int $id, ?string $username = null): User
    {
        $user = new User(
            $id,
            $username ?? $email,
            strtolower($email),
            '127.0.0.1',
            new DateTime(),
            new DateTime(),
            new DateTime('0000-00-00 00:00:00'),
            false
        );
        $this->users[strtolower($email)] = $user;
        return $user;
    }
}

final class RateLimitSecurityAuthenticator extends AuthenticatorTOTPService
{
    public function __construct() {}
    public function hasEnabledAuthenticator(int $userId): bool { return false; }
}

final class RateLimitSecurityTOTP extends TOTPService
{
    public function generateSecret(int $digits = TOTP_DIGITS, int $period = TOTP_PERIOD): string { return 'security-secret'; }
    public function generateTOTP(?string $secret = null, ?int $digits = AUTHENTICATOR_TOTP_DIGITS, ?int $period = AUTHENTICATOR_TOTP_PERIOD): string { return '246810'; }
    public function verifyTOTP(string $secret, string $totp, int $digits = TOTP_DIGITS, int $period = TOTP_PERIOD): bool { return $totp === '246810'; }
}

final class RateLimitSecurityEmail extends EmailService
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $sent = [];
    public function __construct(private readonly ?string $logPath = null) {}
    public function sendEmailTOTP(string $toEmail, string $totp): bool
    {
        $this->sent[] = [$toEmail, $totp];
        if ($this->logPath !== null) {
            file_put_contents($this->logPath, $toEmail . "\n", FILE_APPEND | LOCK_EX);
        }
        return true;
    }
}

final class RateLimitSecurityEmailRepository extends EmailTOTPRepository
{
    public int $stored = 0;
    public function __construct() {}
    public function storeTOTP(int $userId, \src\Business\EmailTOTPPurpose $purpose, string $secret, string $expiresAt): int { return ++$this->stored; }
    public function findAllValidTOTPs(int $userId, \src\Business\EmailTOTPPurpose $purpose): array { return []; }
    public function deleteTOTP(int $id): void {}
}

final class FailingAuthenticationRateLimiter extends AuthenticationRateLimitService
{
    public function __construct() {}
    public function consumeAttempt(
        AuthenticationRateLimitContext $context,
        \src\Business\AuthenticationRateLimitAction $action,
        \src\Business\AuthenticationRateLimitPurpose $purpose,
        array $policies
    ): \src\Business\RateLimitDecision {
        throw new PDOException('rate-limit persistence unavailable');
    }
    public function consumeAttempts(
        AuthenticationRateLimitContext $context,
        array $attempts
    ): \src\Business\RateLimitDecision {
        throw new PDOException('rate-limit persistence unavailable');
    }
}

final class ResetFailingAuthenticationRateLimiter extends AuthenticationRateLimitService
{
    public bool $failReset = true;
    public function __construct() {}
    public function consumeAttempt(
        AuthenticationRateLimitContext $context,
        \src\Business\AuthenticationRateLimitAction $action,
        \src\Business\AuthenticationRateLimitPurpose $purpose,
        array $policies
    ): \src\Business\RateLimitDecision {
        return new \src\Business\RateLimitDecision(true, 1, null);
    }
    public function consumeAttempts(
        AuthenticationRateLimitContext $context,
        array $attempts
    ): \src\Business\RateLimitDecision {
        return new \src\Business\RateLimitDecision(true, 1, null);
    }
    public function resetAfterSuccessfulVerification(
        AuthenticationRateLimitContext $context,
        \src\Business\AuthenticationRateLimitAction $action,
        \src\Business\AuthenticationRateLimitPurpose $purpose
    ): void {
        if ($this->failReset === true) {
            throw new PDOException('verification reset unavailable');
        }
    }
}

function securityAssert(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function securityDatabase(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE authentication_rate_limit (
        bucket_hash TEXT NOT NULL,
        purpose TEXT NOT NULL,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        blocked_until TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (bucket_hash, purpose)
    )');
    $connection = new Connection($pdo);
    return [$pdo, new AuthenticationRateLimitService(
        $connection,
        new AuthenticationRateLimitRepository($connection),
        str_repeat('auth-entry-rate-limit-pepper-', 2)
    )];
}

function securityEntryService(
    RateLimitSecuritySession $session,
    RateLimitSecurityUsers $users,
    RateLimitSecurityEmail $email,
    AuthenticationRateLimitService $limiter,
    ?RateLimitSecurityEmailRepository $repository = null
): AuthEntryService {
    $totp = new RateLimitSecurityTOTP();
    $emailRepository = $repository ?? new RateLimitSecurityEmailRepository();
    $emailTotp = new EmailTOTPService($totp, $emailRepository, $limiter);
    $otp = new AuthEntryOtpService(
        $emailTotp,
        $totp,
        $email,
        $session,
        $limiter,
        new \src\Business\AuthenticationRateLimitContextProvider($session)
    );
    return new AuthEntryService(
        $users,
        new RateLimitSecurityAuthenticator(),
        $emailTotp,
        $email,
        $session,
        null,
        new \app\Service\PendingAuthenticationSession($session),
        $otp,
        new \src\Business\AuthenticationRateLimitContextProvider($session)
    );
}

[$pdo, $limiter] = securityDatabase();
$users = new RateLimitSecurityUsers();
$email = new RateLimitSecurityEmail();
$attempts = [
    ['Victim@Example.test', AuthEntryService::MODE_LOGIN, '192.0.2.1', 'one'],
    ['victim@example.test', AuthEntryService::MODE_REGISTER, '198.51.100.2', 'two'],
    ['VICTIM@example.test', AuthEntryService::MODE_LOGIN, '203.0.113.3', 'three'],
];
foreach ($attempts as [$address, $mode, $ipAddress, $sessionId]) {
    $session = new RateLimitSecuritySession($ipAddress, $sessionId);
    $service = securityEntryService($session, $users, $email, $limiter);
    $result = $mode === AuthEntryService::MODE_LOGIN
        ? $service->beginLogin(new AuthService($session, new CsrfService($session)), $address)
        : $service->beginRegistration(new AuthService($session, new CsrfService($session)), 'user-' . $sessionId, $address);
    securityAssert($result['status'] === AuthEntryService::STATUS_EMAIL_OTP_SENT, 'A permitted unknown-recipient request failed.');
}
$blocked = false;
try {
    $session = new RateLimitSecuritySession('203.0.113.4', 'four');
    securityEntryService($session, $users, $email, $limiter)
        ->beginRegistration(new AuthService($session, new CsrfService($session)), 'user-four', 'victim@example.test');
} catch (RateLimitExceededException) {
    $blocked = true;
}
securityAssert($blocked, 'Changing case, IP, session, and intent bypassed the shared recipient quota.');
securityAssert(count($email->sent) === 3, 'A blocked recipient request sent an email.');

$session = new RateLimitSecuritySession('203.0.113.5', 'independent');
$independent = securityEntryService($session, $users, $email, $limiter)
    ->beginLogin(new AuthService($session, new CsrfService($session)), 'other@example.test');
securityAssert($independent['status'] === AuthEntryService::STATUS_EMAIL_OTP_SENT, 'A different recipient did not retain an independent quota.');

$storedRows = $pdo->query('SELECT bucket_hash, purpose FROM authentication_rate_limit')->fetchAll(PDO::FETCH_ASSOC);
securityAssert(str_contains(serialize($storedRows), 'victim@example.test') === false, 'A raw email address reached rate-limit storage.');
securityAssert(str_contains(serialize($storedRows), '203.0.113.4') === false, 'A raw IP address reached rate-limit storage.');

[$unusedPdo, $ipLimiter] = securityDatabase();
$ipUsers = new RateLimitSecurityUsers();
$ipEmail = new RateLimitSecurityEmail();
for ($attempt = 1; $attempt <= 20; ++$attempt) {
    $session = new RateLimitSecuritySession('192.0.2.100', 'ip-session-' . $attempt);
    securityEntryService($session, $ipUsers, $ipEmail, $ipLimiter)
        ->beginLogin(new AuthService($session, new CsrfService($session)), 'target-' . $attempt . '@example.test');
}
$ipBlocked = false;
try {
    $session = new RateLimitSecuritySession('192.0.2.100', 'ip-session-21');
    securityEntryService($session, $ipUsers, $ipEmail, $ipLimiter)
        ->beginLogin(new AuthService($session, new CsrfService($session)), 'target-21@example.test');
} catch (RateLimitExceededException) {
    $ipBlocked = true;
}
securityAssert($ipBlocked && count($ipEmail->sent) === 20, 'The shared IP quota did not protect multiple recipients.');

[$unusedPdo, $verifyLimiter] = securityDatabase();
$verifySession = new RateLimitSecuritySession('198.51.100.50', 'verify-session');
$verifyUsers = new RateLimitSecurityUsers();
$verifyEmail = new RateLimitSecurityEmail();
$verifyService = securityEntryService($verifySession, $verifyUsers, $verifyEmail, $verifyLimiter);
$verifyAuth = new AuthService($verifySession, new CsrfService($verifySession));
$verifyService->beginLogin($verifyAuth, 'verify@example.test');
for ($attempt = 1; $attempt <= 5; ++$attempt) {
    securityAssert(
        $verifyService->verify($verifyAuth, AuthEntryService::MODE_LOGIN, '000000')['status'] === AuthEntryService::STATUS_INVALID_OTP,
        'An incorrect email-only OTP did not fail.'
    );
}
$verificationBlocked = false;
securityAssert(
    $verifyService->verify($verifyAuth, AuthEntryService::MODE_REGISTER, '000000')['status']
        === AuthEntryService::STATUS_INVALID_OTP,
    'A login OTP was accepted through the registration verification endpoint.'
);
try {
    $verifyService->verify($verifyAuth, AuthEntryService::MODE_LOGIN, '000000');
} catch (RateLimitExceededException) {
    $verificationBlocked = true;
}
securityAssert($verificationBlocked, 'Email-only verification exceeded its intended attempt quota.');

[$unusedPdo, $bindingLimiter] = securityDatabase();
$bindingSession = new RateLimitSecuritySession('198.51.100.60', 'binding-session');
$bindingUsers = new RateLimitSecurityUsers();
$bindingEmail = new RateLimitSecurityEmail();
$bindingService = securityEntryService($bindingSession, $bindingUsers, $bindingEmail, $bindingLimiter);
$bindingAuth = new AuthService($bindingSession, new CsrfService($bindingSession));
$bindingService->beginLogin($bindingAuth, 'Email-A@Example.test');
(new \app\Service\PendingAuthenticationSession($bindingSession))->setEmail('email-b@example.test');
$bindingResult = $bindingService->verify($bindingAuth, AuthEntryService::MODE_LOGIN, '246810');
securityAssert(
    $bindingResult['status'] === AuthEntryService::STATUS_AUTHENTICATED
        && isset($bindingUsers->users['email-a@example.test'])
        && isset($bindingUsers->users['email-b@example.test']) === false,
    'Changing pending email state moved an OTP challenge to a different identity.'
);
$emailBContext = AuthenticationRateLimitContext::forEmail(
    'email-b@example.test',
    '203.0.113.60',
    'email-b-verification'
);
for ($attempt = 1; $attempt <= 5; ++$attempt) {
    $emailBDecision = $bindingLimiter->consumeAttempt(
        $emailBContext,
        \src\Business\AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY,
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN,
        \src\Business\AuthenticationRateLimitPolicies::emailVerification()
    );
    securityAssert($emailBDecision->allowed, 'Email A challenge activity consumed email B verification quota.');
}

[$unusedPdo, $consumeLimiter] = securityDatabase();
$consumeSession = new RateLimitSecuritySession('198.51.100.51', 'consume-session');
$consumeEmail = new RateLimitSecurityEmail();
$consumeTotp = new RateLimitSecurityTOTP();
$consumeOtp = new AuthEntryOtpService(
    new EmailTOTPService($consumeTotp, new RateLimitSecurityEmailRepository(), $consumeLimiter),
    $consumeTotp,
    $consumeEmail,
    $consumeSession,
    $consumeLimiter,
    new \src\Business\AuthenticationRateLimitContextProvider($consumeSession)
);
$consumeContext = AuthenticationRateLimitContext::forEmail('consume@example.test', '198.51.100.51', 'consume-session');
$consumeOtp->issueEmailOnly(
    'consume@example.test',
    \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
);
securityAssert(
    $consumeOtp->verifyEmailOnly(
        '246810',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    ) === 'consume@example.test',
    'A valid email-only OTP failed.'
);
securityAssert(
    $consumeOtp->verifyEmailOnly(
        '246810',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    ) === null,
    'A consumed email-only OTP was accepted twice.'
);
$sharedIssuePurpose = \src\Business\AuthenticationRateLimitAction::EMAIL_RECIPIENT_ISSUE->value
    . '.' . \src\Business\AuthenticationRateLimitPurpose::EMAIL_RECIPIENT->value;
$intentIssuePurpose = \src\Business\AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE->value
    . '.' . \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN->value;
$verifyPurpose = \src\Business\AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY->value
    . '.' . \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN->value;
securityAssert(
    (int) $unusedPdo->query("SELECT COUNT(*) FROM authentication_rate_limit WHERE purpose = '{$sharedIssuePurpose}'")->fetchColumn() === 3
        && (int) $unusedPdo->query("SELECT COUNT(*) FROM authentication_rate_limit WHERE purpose = '{$intentIssuePurpose}'")->fetchColumn() === 3,
    'Successful verification cleared issuance abuse history.'
);
securityAssert(
    (int) $unusedPdo->query("SELECT COUNT(*) FROM authentication_rate_limit WHERE purpose = '{$verifyPurpose}'")->fetchColumn() === 1,
    'Successful verification reset broader verification dimensions than intended.'
);

[$orderingPdo, $orderingLimiter] = securityDatabase();
$orderingSession = new RateLimitSecuritySession('198.51.100.70', 'ordering-session');
$orderingContext = AuthenticationRateLimitContext::forEmail(
    'ordering@example.test',
    '198.51.100.70',
    'ordering-session'
);
for ($attempt = 1; $attempt <= 3; ++$attempt) {
    $orderingLimiter->consumeAttempt(
        $orderingContext,
        \src\Business\AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE,
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN,
        \src\Business\AuthenticationRateLimitPolicies::emailIssuance()
    );
}
$orderingEmail = new RateLimitSecurityEmail();
$orderingTotp = new RateLimitSecurityTOTP();
$orderingOtp = new AuthEntryOtpService(
    new EmailTOTPService($orderingTotp, new RateLimitSecurityEmailRepository(), $orderingLimiter),
    $orderingTotp,
    $orderingEmail,
    $orderingSession,
    $orderingLimiter,
    new \src\Business\AuthenticationRateLimitContextProvider($orderingSession)
);
$orderingBlocked = false;
try {
    $orderingOtp->issueEmailOnly(
        'ordering@example.test',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    );
} catch (RateLimitExceededException) {
    $orderingBlocked = true;
}
securityAssert($orderingBlocked && $orderingEmail->sent === [], 'Intent denial did not stop OTP delivery.');
securityAssert(
    (int) $orderingPdo->query("SELECT COUNT(*) FROM authentication_rate_limit WHERE purpose = '{$sharedIssuePurpose}' AND attempt_count = 1")->fetchColumn() === 3,
    'The atomic limiter did not charge the shared quota before the intent-specific denial.'
);

[$rollbackPdo, $rollbackLimiter] = securityDatabase();
$rollbackPdo->exec("CREATE TRIGGER fail_intent_rate_limit
    BEFORE INSERT ON authentication_rate_limit
    WHEN NEW.purpose = '{$intentIssuePurpose}'
    BEGIN
        SELECT RAISE(ABORT, 'intent persistence failure');
    END");
$rollbackSession = new RateLimitSecuritySession('198.51.100.71', 'rollback-session');
$rollbackEmail = new RateLimitSecurityEmail();
$rollbackTotp = new RateLimitSecurityTOTP();
$rollbackOtp = new AuthEntryOtpService(
    new EmailTOTPService($rollbackTotp, new RateLimitSecurityEmailRepository(), $rollbackLimiter),
    $rollbackTotp,
    $rollbackEmail,
    $rollbackSession,
    $rollbackLimiter,
    new \src\Business\AuthenticationRateLimitContextProvider($rollbackSession)
);
$rollbackFailed = false;
try {
    $rollbackOtp->issueEmailOnly(
        'rollback@example.test',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    );
} catch (PDOException) {
    $rollbackFailed = true;
}
securityAssert($rollbackFailed, 'The second policy persistence failure did not fail closed.');
securityAssert(
    (int) $rollbackPdo->query('SELECT COUNT(*) FROM authentication_rate_limit')->fetchColumn() === 0,
    'The second policy persistence failure did not roll back the shared buckets.'
);
securityAssert(
    $rollbackEmail->sent === []
        && isset($rollbackSession->values['PENDING_EMAIL_ONLY_TOTP_SECRET']) === false,
    'A transactional persistence failure generated deliverable OTP state.'
);

[$unusedPdo, $transitionLimiter] = securityDatabase();
$transitionUsers = new RateLimitSecurityUsers();
$transitionEmail = new RateLimitSecurityEmail();
for ($attempt = 1; $attempt <= 3; ++$attempt) {
    $session = new RateLimitSecuritySession('192.0.2.' . $attempt, 'transition-' . $attempt);
    securityEntryService($session, $transitionUsers, $transitionEmail, $transitionLimiter)
        ->beginLogin(new AuthService($session, new CsrfService($session)), 'transition@example.test');
}
$transitionUsers->addUser('transition@example.test', 777);
$transitionRepository = new RateLimitSecurityEmailRepository();
$transitionBlocked = false;
try {
    $session = new RateLimitSecuritySession('192.0.2.99', 'transition-existing');
    securityEntryService($session, $transitionUsers, $transitionEmail, $transitionLimiter, $transitionRepository)
        ->beginLogin(new AuthService($session, new CsrfService($session)), 'transition@example.test');
} catch (RateLimitExceededException) {
    $transitionBlocked = true;
}
securityAssert($transitionBlocked && $transitionRepository->stored === 0, 'Account creation bypassed the outstanding email-recipient restriction.');

[$unusedPdo, $userLimiter] = securityDatabase();
$userRepository = new RateLimitSecurityEmailRepository();
$userTotp = new RateLimitSecurityTOTP();
$userEmailTotp = new EmailTOTPService($userTotp, $userRepository, $userLimiter);
for ($attempt = 1; $attempt <= 3; ++$attempt) {
    $userEmailTotp->issue(321, \src\Business\EmailTOTPPurpose::LOGIN, AuthenticationRateLimitContext::forUser(321, '192.0.2.' . $attempt, 'user-' . $attempt));
}
$userBlocked = false;
try {
    $userEmailTotp->issue(321, \src\Business\EmailTOTPPurpose::LOGIN, AuthenticationRateLimitContext::forUser(321, '192.0.2.10', 'user-four'));
} catch (RateLimitExceededException) {
    $userBlocked = true;
}
securityAssert($userBlocked, 'The existing UserID issuance limit changed.');

$failureSession = new RateLimitSecuritySession('203.0.113.90', 'failure-session');
$failureEmail = new RateLimitSecurityEmail();
$failureTotp = new RateLimitSecurityTOTP();
$failureLimiter = new FailingAuthenticationRateLimiter();
$failureOtp = new AuthEntryOtpService(
    new EmailTOTPService($failureTotp, new RateLimitSecurityEmailRepository(), $failureLimiter),
    $failureTotp,
    $failureEmail,
    $failureSession,
    $failureLimiter,
    new \src\Business\AuthenticationRateLimitContextProvider($failureSession)
);
$persistenceFailed = false;
try {
    $failureOtp->issueEmailOnly(
        'failure@example.test',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    );
} catch (PDOException) {
    $persistenceFailed = true;
}
securityAssert($persistenceFailed, 'A rate-limit persistence error did not fail closed.');
securityAssert($failureEmail->sent === [], 'A persistence failure still sent an OTP.');
securityAssert(isset($failureSession->values['PENDING_EMAIL_ONLY_TOTP_SECRET']) === false, 'A persistence failure still stored an OTP secret.');

$resetSession = new RateLimitSecuritySession('203.0.113.91', 'reset-session');
$resetEmail = new RateLimitSecurityEmail();
$resetTotp = new RateLimitSecurityTOTP();
$resetLimiter = new ResetFailingAuthenticationRateLimiter();
$resetOtp = new AuthEntryOtpService(
    new EmailTOTPService($resetTotp, new RateLimitSecurityEmailRepository(), $resetLimiter),
    $resetTotp,
    $resetEmail,
    $resetSession,
    $resetLimiter,
    new \src\Business\AuthenticationRateLimitContextProvider($resetSession)
);
$resetOtp->issueEmailOnly(
    'reset@example.test',
    \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
);
$resetFailed = false;
try {
    $resetOtp->verifyEmailOnly(
        '246810',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    );
} catch (PDOException) {
    $resetFailed = true;
}
securityAssert(
    $resetFailed && isset($resetSession->values['PENDING_EMAIL_ONLY_TOTP_SECRET']),
    'A reset persistence failure consumed the OTP before authentication completed.'
);
$resetLimiter->failReset = false;
securityAssert(
    $resetOtp->verifyEmailOnly(
        '246810',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    ) === 'reset@example.test',
    'The OTP could not be retried after a reset persistence failure.'
);

if (function_exists('pcntl_fork') === true) {
    $concurrentDatabasePath = tempnam(sys_get_temp_dir(), 'auth-entry-rate-limit-');
    $concurrentLogPath = tempnam(sys_get_temp_dir(), 'auth-entry-rate-limit-log-');
    $concurrentGatePath = $concurrentDatabasePath . '.gate';
    if ($concurrentDatabasePath === false || $concurrentLogPath === false) {
        throw new RuntimeException('Unable to create concurrent rate-limit test files.');
    }
    $concurrentPdo = new PDO('sqlite:' . $concurrentDatabasePath);
    $concurrentPdo->exec('PRAGMA journal_mode = WAL');
    $concurrentPdo->exec('CREATE TABLE authentication_rate_limit (
        bucket_hash TEXT NOT NULL,
        purpose TEXT NOT NULL,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        blocked_until TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (bucket_hash, purpose)
    )');
    $concurrentPdo = null;
    $children = [];
    for ($attempt = 1; $attempt <= 8; ++$attempt) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a concurrent rate-limit test client.');
        }
        if ($pid > 0) {
            $children[] = $pid;
            continue;
        }
        while (is_file($concurrentGatePath) === false) {
            usleep(1000);
        }
        try {
            $childPdo = new PDO('sqlite:' . $concurrentDatabasePath);
            $childPdo->exec('PRAGMA busy_timeout = 3000');
            $childConnection = new Connection($childPdo);
            $childLimiter = new AuthenticationRateLimitService(
                $childConnection,
                new AuthenticationRateLimitRepository($childConnection),
                str_repeat('concurrent-auth-entry-pepper-', 2)
            );
            $childSession = new RateLimitSecuritySession('198.51.100.' . $attempt, 'concurrent-' . $attempt);
            securityEntryService(
                $childSession,
                new RateLimitSecurityUsers(),
                new RateLimitSecurityEmail($concurrentLogPath),
                $childLimiter
            )->beginLogin(
                new AuthService($childSession, new CsrfService($childSession)),
                'concurrent@example.test'
            );
        } catch (RateLimitExceededException | PDOException) {
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
        exit(0);
    }
    touch($concurrentGatePath);
    foreach ($children as $child) {
        $status = 0;
        pcntl_waitpid($child, $status);
        securityAssert(pcntl_wexitstatus($status) === 0, 'A concurrent rate-limit test client crashed.');
    }
    $concurrentDeliveries = file($concurrentLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    securityAssert(
        is_array($concurrentDeliveries)
            && count($concurrentDeliveries) >= 1
            && count($concurrentDeliveries) <= 3,
        'Concurrent issuance requests exceeded the recipient quota.'
    );
    unlink($concurrentGatePath);
    unlink($concurrentLogPath);
    unlink($concurrentDatabasePath);

    $verificationSessionDirectory = sys_get_temp_dir()
        . '/auth-entry-verification-session-'
        . bin2hex(random_bytes(6));
    if (mkdir($verificationSessionDirectory, 0700) === false) {
        throw new RuntimeException('Unable to create the verification session directory.');
    }
    $verificationLogPath = $verificationSessionDirectory . '/successes.log';
    $verificationGatePath = $verificationSessionDirectory . '/start.gate';
    $verificationSessionId = 'otpconcurrency' . getmypid();
    session_save_path($verificationSessionDirectory);
    session_name('OtpConcurrency');
    session_id($verificationSessionId);
    session_start();
    $_SESSION['_IPaddress'] = '198.51.100.200';
    $_SESSION['_security_rate_limit_id'] = 'verification-concurrency';
    $nativeSession = (new ReflectionClass(SessionService::class))->newInstanceWithoutConstructor();
    $nativeLimiter = new ResetFailingAuthenticationRateLimiter();
    $nativeLimiter->failReset = false;
    $nativeTotp = new RateLimitSecurityTOTP();
    $nativeOtp = new AuthEntryOtpService(
        new EmailTOTPService($nativeTotp, new RateLimitSecurityEmailRepository(), $nativeLimiter),
        $nativeTotp,
        new RateLimitSecurityEmail(),
        $nativeSession,
        $nativeLimiter,
        new \src\Business\AuthenticationRateLimitContextProvider($nativeSession)
    );
    $nativeOtp->issueEmailOnly(
        'verification-concurrency@example.test',
        \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
    );
    session_write_close();

    $verificationChildren = [];
    for ($attempt = 1; $attempt <= 2; ++$attempt) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a concurrent verification client.');
        }
        if ($pid > 0) {
            $verificationChildren[] = $pid;
            continue;
        }
        while (is_file($verificationGatePath) === false) {
            usleep(1000);
        }
        session_id($verificationSessionId);
        session_start();
        $childSession = (new ReflectionClass(SessionService::class))->newInstanceWithoutConstructor();
        $childLimiter = new ResetFailingAuthenticationRateLimiter();
        $childLimiter->failReset = false;
        $childTotp = new RateLimitSecurityTOTP();
        $childOtp = new AuthEntryOtpService(
            new EmailTOTPService($childTotp, new RateLimitSecurityEmailRepository(), $childLimiter),
            $childTotp,
            new RateLimitSecurityEmail(),
            $childSession,
            $childLimiter,
            new \src\Business\AuthenticationRateLimitContextProvider($childSession)
        );
        $verified = $childOtp->verifyEmailOnly(
            '246810',
            \src\Business\AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN
        );
        if ($verified !== null) {
            file_put_contents($verificationLogPath, $verified . "\n", FILE_APPEND | LOCK_EX);
        }
        session_write_close();
        exit(0);
    }
    touch($verificationGatePath);
    foreach ($verificationChildren as $child) {
        $status = 0;
        pcntl_waitpid($child, $status);
        securityAssert(pcntl_wexitstatus($status) === 0, 'A concurrent verification client crashed.');
    }
    $verificationSuccesses = is_file($verificationLogPath)
        ? file($verificationLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : [];
    securityAssert(
        is_array($verificationSuccesses)
            && $verificationSuccesses === ['verification-concurrency@example.test'],
        'Concurrent requests verified the same session OTP more than once.'
    );
    $sessionFile = $verificationSessionDirectory . '/sess_' . $verificationSessionId;
    if (is_file($sessionFile)) {
        unlink($sessionFile);
    }
    if (is_file($verificationLogPath)) {
        unlink($verificationLogPath);
    }
    unlink($verificationGatePath);
    rmdir($verificationSessionDirectory);
}

fwrite(STDOUT, "Auth-entry rate-limit security tests passed.\n");
