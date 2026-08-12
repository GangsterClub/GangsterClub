<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\SessionService;
use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitService;
use src\Business\AuthenticatorTOTPService;
use src\Business\AuthEntryService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RecoveryCodeCodec;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Business\SecurityAuditService;
use src\Business\TOTPService;
use src\Business\UserService;
use src\Controller\RecoveryCodes;
use src\Data\Connection;
use src\Data\Repository\AuthenticationChallengeRepository;
use src\Data\Repository\AuthenticationRateLimitRepository;
use src\Data\Repository\RecoveryCodeRepository;
use src\Data\Repository\SecurityAuditEventRepository;
use src\Data\Repository\UserAuthenticatorTOTPRepository;
use src\Data\Repository\UserRepository;

require_once __DIR__ . '/../vendor/autoload.php';

defined('APP_BASE') || define('APP_BASE', '');
defined('APP_NAME') || define('APP_NAME', 'GangsterClub Test');
defined('AUTHENTICATOR_TOTP_DIGITS') || define('AUTHENTICATOR_TOTP_DIGITS', 6);
defined('AUTHENTICATOR_TOTP_PERIOD') || define('AUTHENTICATOR_TOTP_PERIOD', 30);
defined('TOTP_DIGITS') || define('TOTP_DIGITS', 6);
defined('TOTP_PERIOD') || define('TOTP_PERIOD', 900);
defined('REQUEST_METHOD') || define('REQUEST_METHOD', 'POST');
defined('REQUEST_URI') || define('REQUEST_URI', '/account/recovery-codes');
defined('SRC_CONTROLLER') || define('SRC_CONTROLLER', 'src\\Controller\\');
defined('DOC_ROOT') || define('DOC_ROOT', dirname(__DIR__));
defined('ENVIRONMENT') || define('ENVIRONMENT', 'testing');
defined('DEVELOPMENT') || define('DEVELOPMENT', false);
defined('WEB_ROOT') || define('WEB_ROOT', 'http://example.test' . APP_BASE . '/');
defined('APP_MAX_AGE') || define('APP_MAX_AGE', 7200);

if (function_exists('__') === false) {
    function __(string $key, array $parameters = []): string
    {
        foreach ($parameters as $name => $value) {
            $key = str_replace(':' . $name, (string) $value, $key);
        }
        return $key;
    }
}

final class RecoveryHttpSession extends SessionService
{
    public array $values = ['_IPaddress' => '192.0.2.80'];
    public array $flashes = [];

    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void { $this->values['_regenerated'] = ($this->values['_regenerated'] ?? 0) + 1; }
    public function flash(string $bag, string $type, string $message): void { $this->flashes[$bag][$type][] = $message; }
    public function consumeFlash(string $bag): array
    {
        $messages = $this->flashes[$bag] ?? ['errors' => [], 'success' => []];
        unset($this->flashes[$bag]);
        return [
            'errors' => $messages['errors'] ?? [],
            'success' => $messages['success'] ?? [],
        ];
    }
}

final class RecoveryHttpRequest extends Request
{
    public function __construct(
        private string $testMethod = 'GET',
        private array $fields = [],
        private array $testHeaders = []
    ) {}
    public function post(string $key, $default = null): mixed { return $this->fields[$key] ?? $default; }
    public function getMethod(): string { return $this->testMethod; }
    public function getHeader(string $key): mixed { return $this->testHeaders[$key] ?? null; }
}

final class RecoveryHttpTranslation
{
    public function setFile(string $file): void {}
}

final class RecoveryHttpEmail extends EmailService
{
    public array $otpMessages = [];
    public array $securityMessages = [];
    public function __construct() {}
    public function sendEmailTOTP(string $toEmail, string $totp): bool
    {
        $this->otpMessages[] = [$toEmail, $totp];
        return true;
    }
    public function sendSecurityNotification(string $toEmail, string $subject, string $message): bool
    {
        $this->securityMessages[] = [$toEmail, $subject, $message];
        return true;
    }
}

final class RecoveryHttpEmailTotp extends EmailTOTPService
{
    public function __construct() {}
    public function issue(int $userId, \src\Business\EmailTOTPPurpose $purpose, \src\Business\AuthenticationRateLimitContext $context): \src\Business\IssuedEmailTOTP { return new \src\Business\IssuedEmailTOTP(1, '111111'); }
    public function verify(int $userId, \src\Business\EmailTOTPPurpose $purpose, string $totp, \src\Business\AuthenticationRateLimitContext $context): bool
    {
        return $totp === '111111';
    }
    public function cancelIssued(\src\Business\IssuedEmailTOTP $issued): void {}
}

final class RecoveryHttpAuthenticator extends AuthenticatorTOTPService
{
    public function __construct(private UserAuthenticatorTOTPRepository $records) {}
    public function hasEnabledAuthenticator(int $userId): bool { return $this->records->findByUserId($userId) !== false; }
    public function generateSecret(int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): string
    {
        return 'PENDINGSECRETVALUE';
    }
    public function verifySecret(string $secret, string $code, int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): bool
    {
        return $secret === 'PENDINGSECRETVALUE' && $code === '123456';
    }
    public function verifyCode(int $userId, string $code): bool
    {
        return $this->hasEnabledAuthenticator($userId) && $code === '654321';
    }
    public function generateProvisioningUri(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): string {
        return 'otpauth://totp/test?secret=' . rawurlencode($secret);
    }
    public function generateQRCode(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): string {
        return 'data:image/png;base64,test';
    }
}

final class RecoveryHttpApplication extends Application
{
    public function __construct(public array $services) {}
    public function get(string $name): object { return $this->services[$name] ?? throw new RuntimeException($name . ' is missing.'); }
}

function recoveryAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function recoveryAssertTrue(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function recoveryJsonResponse(callable $action): array
{
    $response = $action();
    $payload = json_decode($response->getContent(), true);
    if (is_array($payload) === false) {
        throw new RuntimeException('Expected a structured JSON response.');
    }
    return [$response, $payload];
}

function recoveryPost(array $fields): RecoveryHttpRequest
{
    return new RecoveryHttpRequest('POST', $fields, [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);
}

$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo);
$schema = [
    'CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        ip_address TEXT NOT NULL,
        browser_session_version INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        deleted_at TEXT NULL
    )',
    'CREATE TABLE user_authenticator_totp (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        secret TEXT NOT NULL,
        digits INTEGER NOT NULL DEFAULT 6,
        period INTEGER NOT NULL DEFAULT 30,
        generation INTEGER NOT NULL DEFAULT 1,
        enabled_at TEXT NOT NULL,
        last_verified_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )',
    'CREATE TABLE authentication_rate_limit (
        bucket_hash TEXT NOT NULL,
        purpose TEXT NOT NULL,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        blocked_until TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
$now = '2030-01-02 03:04:05';
$pdo->exec("INSERT INTO user VALUES
    (1, 'new-user', 'new@example.test', '192.0.2.1', 1, '{$now}', '{$now}', NULL),
    (2, 'existing-user', 'existing@example.test', '192.0.2.2', 1, '{$now}', '{$now}', NULL),
    (3, 'lost-session-user', 'lost-session@example.test', '192.0.2.3', 1, '{$now}', '{$now}', NULL)");
$pdo->exec("INSERT INTO user_authenticator_totp
    (user_id, secret, digits, period, generation, enabled_at)
    VALUES (2, 'ACTIVESECRET', 6, 30, 1, '{$now}')");

(new Router())->load(DOC_ROOT . '/src/resources/routes.yaml');
$userRepository = new UserRepository($connection);
$authenticatorRepository = new UserAuthenticatorTOTPRepository($connection);
$recoveryRepository = new RecoveryCodeRepository($connection);
$challengeRepository = new AuthenticationChallengeRepository($connection);
$rateRepository = new AuthenticationRateLimitRepository($connection);
$auditRepository = new SecurityAuditEventRepository($connection);
$challengeService = new AuthenticationChallengeService(
    $connection,
    $challengeRepository,
    str_repeat('challenge-pepper-', 3)
);
$rateService = new AuthenticationRateLimitService(
    $connection,
    $rateRepository,
    str_repeat('rate-limit-pepper-', 3)
);
$auditService = new SecurityAuditService($auditRepository);
$recoveryService = new RecoveryCodeService(
    $connection,
    $recoveryRepository,
    new RecoveryCodeCodec(str_repeat('recovery-pepper-', 3)),
    $rateService,
    $auditService
);
$featureService = new RecoveryFeatureService(
    $connection,
    $challengeService,
    $recoveryService,
    $recoveryRepository,
    $authenticatorRepository,
    $userRepository,
    $auditService
);
$authenticatorService = new RecoveryHttpAuthenticator($authenticatorRepository);
$emailTotp = new RecoveryHttpEmailTotp();
$email = new RecoveryHttpEmail();
$users = new UserService($userRepository);
$twig = new Twig\Environment(new Twig\Loader\ArrayLoader([
    'recovery-flow.twig' => 'state={{ flow.state }} codes={{ flow.codes ? flow.codes|join(",") : "" }}',
]));

$makeController = static function (RecoveryHttpSession $session) use (
    $connection,
    $challengeService,
    $rateService,
    $recoveryService,
    $featureService,
    $authenticatorService,
    $emailTotp,
    $email,
    $users,
    $twig,
    $userRepository
): array {
    $csrf = new CsrfService($session);
    $auth = new AuthService($session, $csrf, $userRepository);
    $application = new RecoveryHttpApplication([
        \src\Data\Connection::class => $connection,
        \app\Service\SessionService::class => $session,
        \app\Service\CsrfService::class => $csrf,
        \app\Service\AuthService::class => $auth,
        \app\Service\TranslationService::class => new RecoveryHttpTranslation(),
        \Twig\Environment::class => $twig,
        \src\Business\AuthenticationChallengeService::class => $challengeService,
        \src\Business\AuthenticationRateLimitService::class => $rateService,
        \src\Business\RecoveryCodeService::class => $recoveryService,
        \src\Business\RecoveryFeatureService::class => $featureService,
        \src\Business\AuthenticatorTOTPService::class => $authenticatorService,
        \src\Business\EmailTOTPService::class => $emailTotp,
        \src\Business\EmailService::class => $email,
        \src\Business\UserService::class => $users,
    ]);
    return [new RecoveryCodes($application), $auth];
};

// Recovery presentation messages remain scoped to their destination and challenge purpose.
$flashSession = new RecoveryHttpSession();
[$flashController, $flashAuth] = $makeController($flashSession);
$flashAuth->setPendingLoginEmail('lost-session@example.test');
$flashAuth->setPendingUserId(3);
$flashAuth->setLoginAuthenticatorRequired(true);
for ($attempt = 0; $attempt < 5; ++$attempt) {
    $unavailableLostResponse = $flashController->startLostAuthenticator(
        new RecoveryHttpRequest('POST')
    );
    recoveryAssertSame(303, $unavailableLostResponse->getStatusCode(), 'Unavailable lost recovery must retain PRG.');
    recoveryAssertTrue(
        in_array('Location: ' . Router::path('login'), $unavailableLostResponse->getHeaders(), true),
        'Unavailable pre-authentication recovery must explicitly return to login.'
    );
    $loginMessages = $flashSession->consumeFlash('login');
    recoveryAssertSame(
        ['recovery.lost-replacement-unavailable'],
        $loginMessages['errors'],
        'Each unavailable lost-recovery request must report once through the login flash bag.'
    );
    recoveryAssertSame(
        false,
        isset($flashSession->flashes['recovery']),
        'Lost-recovery failures redirected to login must never accumulate in the shared recovery bag.'
    );
}

for ($stale = 0; $stale < 5; ++$stale) {
    $flashSession->flash(
        'recovery',
        'errors',
        'recovery.lost-replacement-unavailable'
    );
}
$flashAuth->loginUser(3);
recoveryJsonResponse(fn () => $flashController->startEnrollment(recoveryPost([])));
recoveryAssertSame(
    false,
    isset($flashSession->flashes['recovery']),
    'Starting a new ceremony must discard legacy shared recovery presentation messages.'
);
$invalidEnrollmentResponse = $flashController->account(new RecoveryHttpRequest('POST', [
    'action' => 'verify_email',
    'email_code' => '000000',
]));
recoveryAssertSame(303, $invalidEnrollmentResponse->getStatusCode(), 'Non-JavaScript enrollment validation must retain PRG.');
recoveryAssertSame(
    ['recovery.email-code-invalid'],
    $flashSession->flashes['recovery.authenticator_enrollment']['errors'] ?? [],
    'Enrollment validation messages must be scoped to the enrollment challenge purpose.'
);
recoveryAssertSame(
    false,
    isset($flashSession->flashes['recovery']),
    'Purpose-scoped enrollment errors must not recreate the shared recovery bag.'
);
$flashController->account(new RecoveryHttpRequest('GET'));
$flashController->account(new RecoveryHttpRequest('POST', ['action' => 'cancel']));
recoveryAssertSame(
    ['recovery.cancelled'],
    $flashSession->flashes['account']['success'] ?? [],
    'Terminal authenticated recovery messages must be delivered to the account destination.'
);

// 1. Existing authenticator user creates and acknowledges the initial set.
$existingSession = new RecoveryHttpSession();
[$controller, $existingAuth] = $makeController($existingSession);
$existingAuth->loginUser(2);
[, $payload] = recoveryJsonResponse(fn () => $controller->startInitial(recoveryPost([])));
recoveryAssertSame('fresh_reauthentication_pending', $payload['state'], 'Initial setup must start with fresh authenticator verification.');
recoveryAssertTrue(
    array_diff(['success', 'state', 'nextStep', 'message', 'errors', 'redirect'], array_keys($payload)) === [],
    'Enhanced modal responses must expose the stable structured response fields.'
);
[$displayJsonResponse, $payload] = recoveryJsonResponse(fn () => $controller->account(recoveryPost([
    'action' => 'verify_authenticator',
    'authenticator_code' => '654321',
])));
recoveryAssertSame('recovery_codes_presented_unacknowledged', $payload['state'], 'Initial setup must generate a pending displayed set.');
$initialCodes = array_map('strval', $payload['codes'] ?? []);
recoveryAssertSame(10, count($initialCodes), 'Initial setup must generate ten codes.');
recoveryAssertTrue(
    in_array('Cache-Control: no-store, private', $displayJsonResponse->getHeaders(), true),
    'One-time JSON display responses must prevent caching.'
);
$displayResponse = $controller->account(new RecoveryHttpRequest('GET'));
recoveryAssertTrue(str_contains($displayResponse->getContent(), $initialCodes[0]) === false, 'A GET refresh must not redisplay plaintext codes.');
recoveryAssertTrue(
    str_contains(json_encode($existingSession->values, JSON_THROW_ON_ERROR), $initialCodes[0]) === false,
    'Recovery-code plaintext must not be staged in the server-side session.'
);
[, $payload] = recoveryJsonResponse(fn () => $controller->account(recoveryPost([
    'action' => 'acknowledge',
    'saved_codes' => '1',
])));
recoveryAssertSame(true, $payload['success'], 'Existing authenticator users must be able to activate their first acknowledged set.');
$initialSetId = $featureService->getActiveRecoverySetId(2);
recoveryAssertTrue($initialSetId !== null, 'Initial acknowledgement must establish one active set.');

// 2. Recovery-code fallback substitutes for the authenticator during normal login.
$loginSession = new RecoveryHttpSession();
$loginAuth = new AuthService($loginSession, new CsrfService($loginSession), $userRepository);
$loginService = new AuthEntryService(
    $users,
    $authenticatorService,
    $emailTotp,
    new TOTPService(),
    $email,
    $loginSession,
    $recoveryService
);
$beginLogin = $loginService->beginLogin($loginAuth, 'existing@example.test');
recoveryAssertSame(AuthEntryService::STATUS_AUTHENTICATOR_CODE_REQUIRED, $beginLogin['status'], 'Authenticator login must continue to request its existing factor.');
$fallback = $loginService->verifyRecoveryCode(
    $loginAuth,
    $initialCodes[0],
    AuthenticationRateLimitContext::forUser(2, '192.0.2.80', 'login-session')
);
recoveryAssertSame(AuthEntryService::STATUS_AUTHENTICATED, $fallback['status'], 'An unused recovery code must complete authenticator login fallback.');
recoveryAssertSame(9, $fallback['remainingCount'], 'Fallback must report the remaining active-code count.');
$invalidLoginSession = new RecoveryHttpSession();
$invalidLoginAuth = new AuthService($invalidLoginSession, new CsrfService($invalidLoginSession), $userRepository);
$invalidLoginService = new AuthEntryService(
    $users,
    $authenticatorService,
    $emailTotp,
    new TOTPService(),
    $email,
    $invalidLoginSession,
    $recoveryService
);
$invalidLoginService->beginLogin($invalidLoginAuth, 'existing@example.test');
$invalidFallback = $invalidLoginService->verifyRecoveryCode(
    $invalidLoginAuth,
    $initialCodes[0],
    AuthenticationRateLimitContext::forUser(2, '192.0.2.81', 'invalid-login-session')
);
recoveryAssertSame(
    AuthEntryService::STATUS_INVALID_RECOVERY_CODE,
    $invalidFallback['status'],
    'A used or invalid recovery code must return a recovery-specific failure instead of a generic OTP failure.'
);

// 3. New enrollment keeps the authenticator inactive until displayed codes are acknowledged.
$enrollmentSession = new RecoveryHttpSession();
[$enrollmentController, $enrollmentAuth] = $makeController($enrollmentSession);
$enrollmentAuth->loginUser(1);
[, $payload] = recoveryJsonResponse(fn () => $enrollmentController->startEnrollment(recoveryPost([])));
recoveryAssertSame('email_verification_pending', $payload['state'], 'Enrollment must begin with email verification.');
[, $payload] = recoveryJsonResponse(fn () => $enrollmentController->account(recoveryPost([
    'action' => 'verify_email',
    'email_code' => '111111',
])));
recoveryAssertSame('authenticator_configuration_presented', $payload['state'], 'The pending secret must be presented only after email verification.');
[, $payload] = recoveryJsonResponse(fn () => $enrollmentController->account(recoveryPost([
    'action' => 'verify_authenticator',
    'authenticator_code' => '123456',
])));
recoveryAssertSame('recovery_codes_presented_unacknowledged', $payload['state'], 'Verified enrollment must proceed to mandatory recovery-code display.');
recoveryAssertSame(false, $authenticatorService->hasEnabledAuthenticator(1), 'Enrollment must remain inactive before acknowledgement.');
$enrollmentCodes = array_map('strval', $payload['codes'] ?? []);
$firstEnrollmentSetId = (int) $enrollmentSession->values[AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID];
$replacementDisplay = $enrollmentController->account(new RecoveryHttpRequest('POST', [
    'action' => 'codes_unavailable',
]));
recoveryAssertTrue(
    in_array('Cache-Control: no-store, private', $replacementDisplay->getHeaders(), true),
    'One-time HTML display responses must prevent caching.'
);
recoveryAssertTrue(
    preg_match('/[0-9A-HJKMNP-TV-Z]{5}(?:-[0-9A-HJKMNP-TV-Z]{5}){3}/', $replacementDisplay->getContent()) === 1,
    'The non-JavaScript POST path must return the deliberate one-time code display directly.'
);
$replacementEnrollmentSetId = (int) $enrollmentSession->values[AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID];
recoveryAssertTrue($replacementEnrollmentSetId !== $firstEnrollmentSetId, 'Lost display must never reuse the same pending set.');
recoveryAssertSame(
    'invalidated',
    $pdo->query('SELECT status FROM recovery_code_set WHERE id = ' . $firstEnrollmentSetId)->fetchColumn(),
    'Lost display must invalidate only the inaccessible pending set.'
);
$enrollmentSession->set(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID, 999999);
$enrollmentSession->set(AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED, 999999);
$atomicEnrollmentFailure = false;
try {
    $enrollmentController->account(recoveryPost([
        'action' => 'acknowledge',
        'saved_codes' => '1',
    ]));
} catch (RuntimeException) {
    $atomicEnrollmentFailure = true;
}
recoveryAssertSame(true, $atomicEnrollmentFailure, 'A failed cross-credential activation must fail closed.');
recoveryAssertSame(
    false,
    $authenticatorService->hasEnabledAuthenticator(1),
    'A failed recovery-set activation must roll back authenticator activation.'
);
$enrollmentSession->set(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID, $replacementEnrollmentSetId);
$enrollmentSession->set(AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED, $replacementEnrollmentSetId);
[, $payload] = recoveryJsonResponse(fn () => $enrollmentController->account(recoveryPost([
    'action' => 'acknowledge',
    'saved_codes' => '1',
])));
recoveryAssertSame(true, $payload['success'], 'Acknowledgement must atomically activate enrollment and its recovery set.');
recoveryAssertSame(true, $authenticatorService->hasEnabledAuthenticator(1), 'The authenticator must be active after acknowledgement.');
recoveryAssertSame(10, $featureService->getUnusedRecoveryCodeCount(1), 'Enrollment must activate all ten acknowledged codes.');

// 4. Replacement leaves the old set active until acknowledgement, then swaps it.
[, $payload] = recoveryJsonResponse(fn () => $controller->startReplacement(recoveryPost([])));
recoveryAssertSame('fresh_reauthentication_pending', $payload['state'], 'Replacement must require fresh reauthentication.');
recoveryJsonResponse(fn () => $controller->account(recoveryPost([
    'action' => 'verify_authenticator',
    'authenticator_code' => '654321',
])));
[, $payload] = recoveryJsonResponse(fn () => $controller->account(recoveryPost([
    'action' => 'confirm_replacement',
    'confirm_replacement' => '1',
])));
recoveryAssertSame('replacement_codes_presented_unacknowledged', $payload['state'], 'Replacement confirmation must generate a pending set.');
$replacementCodes = array_map('strval', $payload['codes'] ?? []);
recoveryAssertSame($initialSetId, $featureService->getActiveRecoverySetId(2), 'The old set must remain active before replacement acknowledgement.');
recoveryJsonResponse(fn () => $controller->account(recoveryPost([
    'action' => 'acknowledge',
    'saved_codes' => '1',
])));
$replacementSetId = $featureService->getActiveRecoverySetId(2);
recoveryAssertTrue($replacementSetId !== null && $replacementSetId !== $initialSetId, 'Acknowledgement must atomically swap to the replacement set.');
$oldStatus = $pdo->query('SELECT status FROM recovery_code_set WHERE id = ' . (int) $initialSetId)->fetchColumn();
recoveryAssertSame('invalidated', $oldStatus, 'The previous set must be invalidated during the swap.');

// Abandoning another replacement ceremony must preserve the active replacement set.
recoveryJsonResponse(fn () => $controller->startReplacement(recoveryPost([])));
recoveryJsonResponse(fn () => $controller->account(recoveryPost(['action' => 'cancel'])));
recoveryAssertSame(
    $replacementSetId,
    $featureService->getActiveRecoverySetId(2),
    'Cancelling a replacement must preserve the currently active set.'
);

// 5. Lost-authenticator recovery consumes an old code, replaces both credentials, and revokes old sessions.
$oldBrowserSession = new RecoveryHttpSession();
$oldBrowserAuth = new AuthService($oldBrowserSession, new CsrfService($oldBrowserSession), $userRepository);
$oldBrowserAuth->loginUser(2);

$lostSession = new RecoveryHttpSession();
[$lostController, $lostAuth] = $makeController($lostSession);
$lostAuth->setPendingLoginEmail('existing@example.test');
$lostAuth->setPendingUserId(2);
$lostAuth->setLoginAuthenticatorRequired(true);
[, $payload] = recoveryJsonResponse(fn () => $lostController->startLostAuthenticator(recoveryPost([])));
recoveryAssertSame('email_verification_pending', $payload['state'], 'Lost-authenticator replacement must begin with email verification.');
recoveryJsonResponse(fn () => $lostController->lostAuthenticator(recoveryPost([
    'action' => 'verify_email',
    'email_code' => '111111',
])));
[, $payload] = recoveryJsonResponse(fn () => $lostController->lostAuthenticator(recoveryPost([
    'action' => 'verify_recovery_code',
    'recovery_code' => $replacementCodes[0],
])));
recoveryAssertSame('replacement_warning_presented', $payload['state'], 'A consumed active code must lead to the explicit replacement warning.');
recoveryJsonResponse(fn () => $lostController->lostAuthenticator(recoveryPost([
    'action' => 'confirm_replacement',
    'confirm_replacement' => '1',
])));
$lostDisplay = recoveryJsonResponse(fn () => $lostController->lostAuthenticator(recoveryPost([
    'action' => 'verify_authenticator',
    'authenticator_code' => '123456',
])));
$lostCodes = array_map('strval', $lostDisplay[1]['codes'] ?? []);
recoveryAssertSame(10, count($lostCodes), 'Lost-authenticator replacement must generate a fresh ten-code set.');
recoveryAssertSame($replacementSetId, $featureService->getActiveRecoverySetId(2), 'Old credentials must remain active until final acknowledgement.');
[, $payload] = recoveryJsonResponse(fn () => $lostController->lostAuthenticator(recoveryPost([
    'action' => 'acknowledge',
    'saved_codes' => '1',
])));
recoveryAssertSame(true, $payload['success'], 'Lost-authenticator acknowledgement must complete the replacement.');
recoveryAssertSame(2, $featureService->getAuthenticatorGeneration(2), 'Lost replacement must advance authenticator generation.');
recoveryAssertSame(2, $lostAuth->getAuthenticatedUserId(), 'The protected replacement session must become the current authenticated session.');
recoveryAssertSame(null, $oldBrowserAuth->getAuthenticatedUserId(), 'A prior browser session must be revoked by the version change.');
$legacyBrowserSession = new RecoveryHttpSession();
$legacyBrowserSession->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 2);
$legacyBrowserAuth = new AuthService(
    $legacyBrowserSession,
    new CsrfService($legacyBrowserSession),
    $userRepository
);
recoveryAssertSame(
    null,
    $legacyBrowserAuth->getAuthenticatedUserId(),
    'An authenticated legacy session without a recorded version must fail closed.'
);
recoveryAssertTrue(
    count(array_filter(
        $email->securityMessages,
        static fn (array $message): bool => in_array($message[1], [
            'Recovery code used',
            'Recovery codes replaced',
            'Authenticator replaced',
        ], true)
    )) >= 3,
    'Successful recovery use and replacements must send security notifications.'
);

$activeSets = (int) $pdo->query("SELECT COUNT(*) FROM recovery_code_set WHERE user_id = 2 AND status = 'active'")->fetchColumn();
recoveryAssertSame(1, $activeSets, 'No flow may leave two active recovery-code sets.');

$lostSecretSession = new RecoveryHttpSession();
[$lostSecretController, $lostSecretAuth] = $makeController($lostSecretSession);
$lostSecretAuth->loginUser(3);
recoveryJsonResponse(fn () => $lostSecretController->startEnrollment(recoveryPost([])));
recoveryJsonResponse(fn () => $lostSecretController->account(recoveryPost([
    'action' => 'verify_email',
    'email_code' => '111111',
])));
$lostSecretSession->remove(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET);
$lostSecretResponse = $lostSecretController->account(new RecoveryHttpRequest('GET'));
recoveryAssertSame(303, $lostSecretResponse->getStatusCode(), 'Losing the server-side pending secret must force a restart.');
recoveryAssertSame(
    false,
    $authenticatorService->hasEnabledAuthenticator(3),
    'Losing pending-secret session state must leave active credentials unchanged.'
);
recoveryAssertTrue(
    $pdo->query('SELECT cancelled_at FROM authentication_challenge WHERE user_id = 3 ORDER BY id DESC LIMIT 1')->fetchColumn() !== false,
    'Losing pending-secret session state must cancel the corresponding challenge.'
);

recoveryAssertSame(true, $featureService->disableAuthenticatorAndRecoveryCodes(1), 'Verified normal removal must complete atomically.');
recoveryAssertSame(false, $authenticatorService->hasEnabledAuthenticator(1), 'Normal removal must delete the active authenticator.');
recoveryAssertSame(null, $featureService->getActiveRecoverySetId(1), 'Normal removal must invalidate associated recovery codes.');
$routes = file_get_contents(__DIR__ . '/../src/resources/routes.yaml');
recoveryAssertTrue(
    is_string($routes)
    && str_contains($routes, '/account/recovery-codes')
    && str_contains($routes, '/login/recovery'),
    'Both authenticated and lost-authenticator HTTP routes must remain registered.'
);
$flowTemplate = file_get_contents(__DIR__ . '/../src/View/recovery-flow.twig');
$authEntryTemplate = file_get_contents(__DIR__ . '/../src/View/partial/auth-entry.twig');
recoveryAssertTrue(
    is_string($flowTemplate)
    && str_contains($flowTemplate, 'csrf_field()')
    && str_contains($flowTemplate, 'data-recovery-form')
    && str_contains($flowTemplate, 'codes_unavailable'),
    'The modal flow must retain CSRF forms, progressive enhancement hooks, and lost-display recovery.'
);
recoveryAssertTrue(
    is_string($authEntryTemplate)
    && str_contains($authEntryTemplate, 'loginRecovery.errors')
    && str_contains($authEntryTemplate, 'loginRecovery.errors %}open'),
    'Recovery-code failures must remain inside an open recovery fallback panel.'
);
$enhancementScript = file_get_contents(__DIR__ . '/../web/js/recovery-flow.js');
$accountTemplate = file_get_contents(__DIR__ . '/../src/View/account.twig');
recoveryAssertTrue(
    is_string($enhancementScript)
    && str_contains($enhancementScript, 'control.disabled = busy')
    && str_contains($enhancementScript, 'status.focus()')
    && str_contains($enhancementScript, "Accept: 'application/json'")
    && str_contains($enhancementScript, '[data-security-modal-form]'),
    'Progressive enhancement must lock active controls, manage focus, and request structured JSON.'
);
recoveryAssertTrue(
    is_string($accountTemplate)
    && str_contains($accountTemplate, 'data-security-modal-form')
    && substr_count($accountTemplate, 'data-modal-status') >= 3
    && str_contains($accountTemplate, 'recovery-replacement-warning-title'),
    'Account-security dialogs must share in-modal status handling and the polished warning composition.'
);

fwrite(STDOUT, "Recovery Feature HTTP tests passed.\n");
