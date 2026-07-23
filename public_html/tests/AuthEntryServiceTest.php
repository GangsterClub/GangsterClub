<?php

declare(strict_types=1);

use app\Container\Application;
use app\Service\AuthService;
use app\Service\JWTService;
use src\Business\AuthEntryService;
use src\Business\EmailService;
use src\Business\MFATOTPService;
use src\Business\TOTPEmailService;
use src\Business\TOTPService;
use src\Business\UserService;
use src\Entity\User;

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;
const MFA_TOTP_DIGITS = 6;
const MFA_TOTP_PERIOD = 30;

require_once __DIR__ . '/../app/Container/Container.php';
require_once __DIR__ . '/../app/Container/Application.php';
require_once __DIR__ . '/../app/Service/SessionService.php';
require_once __DIR__ . '/../app/Service/AuthSessionKeys.php';
require_once __DIR__ . '/../app/Service/AuthService.php';
require_once __DIR__ . '/../app/Service/JWTService.php';
require_once __DIR__ . '/../src/Entity/User.php';
require_once __DIR__ . '/../src/Business/UserService.php';
require_once __DIR__ . '/../src/Business/TOTPService.php';
require_once __DIR__ . '/../src/Business/TOTPEmailService.php';
require_once __DIR__ . '/../src/Business/MFATOTPService.php';
require_once __DIR__ . '/../src/Business/EmailService.php';
require_once __DIR__ . '/../src/Business/AuthEntryService.php';

final class AuthEntryServiceTestSession extends app\Service\SessionService
{
    public array $values = ['_IPaddress' => '127.0.0.1'];
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void {}
}
final class AuthEntryServiceTestCsrf { public function rotateToken(): void {} }
final class AuthEntryServiceTestApplication extends Application
{
    public AuthEntryServiceTestSession $session;
    public function __construct() { $this->session = new AuthEntryServiceTestSession(); }
    public function get(string $name): ?object { return $name === 'sessionService' ? $this->session : ($name === 'csrfService' ? new AuthEntryServiceTestCsrf() : null); }
}
final class FakeUserService extends UserService
{
    public array $byEmail = [];
    public array $byUsername = [];
    public int $createByEmailCalls = 0;
    public int $createUserCalls = 0;
    public function __construct() {}
    public function getUserByEmail(string $email): ?User { return $this->byEmail[$email] ?? null; }
    public function getUserByUsername(string $username): ?User { return $this->byUsername[$username] ?? null; }
    public function createUserByEmail(string $email, string $ipAddress, ?User $user = null): ?User { $this->createByEmailCalls++; return $this->byEmail[$email] = makeUser(42, $email, $email); }
    public function createUser(string $username, string $email, string $ipAddress): ?User { $this->createUserCalls++; return $this->byEmail[$email] = makeUser(43, $username, $email); }
}
final class FakeMfaService extends MFATOTPService { public bool $enabled=false; public bool $valid=false; public function __construct() {} public function hasEnabledMfa(int $userId): bool { return $this->enabled; } public function verifyCode(int $userId, string $code): bool { return $this->valid; } }
final class FakeTotpEmailService extends TOTPEmailService { public bool $valid=true; public function __construct() {} public function generateEmailTOTP(int $userId): string { return '111111'; } public function verifyEmailTOTP(int $userId, string $totp): bool { return $this->valid; } }
final class FakeTotpService extends TOTPService { public function generateSecret(int $digits = TOTP_DIGITS, int $period = TOTP_PERIOD): string { return 'secret'; } public function generateTOTP(?string $secret = null, ?int $digits = MFA_TOTP_DIGITS, ?int $period = MFA_TOTP_PERIOD): string { return '222222'; } public function verifyTOTP(string $secret, string $totp, int $digits = TOTP_DIGITS, int $period = TOTP_PERIOD): bool { return $secret === 'secret' && $totp === '222222'; } }
final class FakeEmailService extends EmailService { public array $sent=[]; public function __construct() {} public function sendTOTPEmail(string $toEmail, string $totp): bool { $this->sent[] = [$toEmail, $totp]; return true; } }
final class FakeJwtService extends JWTService { public function __construct() {} public function authenticate(string $email, bool $totpValid = false): string|false { return $totpValid ? 'jwt-for-'.$email : false; } }
function makeService(
    AuthEntryServiceTestSession $session,
    FakeUserService $users,
    ?FakeMfaService $mfa = null,
    ?FakeTotpEmailService $emailTotp = null,
    ?FakeTotpService $totp = null,
    ?FakeEmailService $email = null,
    ?FakeJwtService $jwt = null
): AuthEntryService {
    return new AuthEntryService(
        $users,
        $mfa ?? new FakeMfaService(),
        $emailTotp ?? new FakeTotpEmailService(),
        $totp ?? new FakeTotpService(),
        $email ?? new FakeEmailService(),
        $jwt ?? new FakeJwtService(),
        $session
    );
}
function makeUser(int $id, string $username, string $email): User { return new User($id, $username, $email, '127.0.0.1', new DateTime(), new DateTime(), new DateTime('0000-00-00 00:00:00')); }
function assertSameValue(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) { throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }

$app = new AuthEntryServiceTestApplication();
$users = new FakeUserService();
$svc = makeService($app->session, $users);
$auth = new AuthService($app);
$result = $svc->beginLogin($auth, 'new@example.test');
assertSameValue(AuthEntryService::STATUS_EMAIL_OTP_SENT, $result['status'], 'Unknown login should send an email-only OTP.');
assertSameValue(0, $users->createByEmailCalls, 'Unknown login must not create the user before TOTP verification.');
$result = $svc->verify($auth, AuthEntryService::MODE_LOGIN, '222222');
assertSameValue(AuthEntryService::STATUS_AUTHENTICATED, $result['status'], 'Unknown login should authenticate after first email TOTP verification.');
assertSameValue(1, $users->createByEmailCalls, 'Unknown login should create by email only after verification.');

$app = new AuthEntryServiceTestApplication();
$users = new FakeUserService();
$svc = makeService($app->session, $users);
$auth = new AuthService($app);
$result = $svc->beginRegistration($auth, 'alice', 'alice@example.test');
assertSameValue(AuthEntryService::STATUS_EMAIL_OTP_SENT, $result['status'], 'Registration should send an email-only OTP.');
assertSameValue(0, $users->createUserCalls, 'Registration must not create the user before TOTP verification.');
$result = $svc->verify($auth, AuthEntryService::MODE_REGISTER, '222222');
assertSameValue(AuthEntryService::STATUS_AUTHENTICATED, $result['status'], 'Registration should authenticate after email TOTP verification.');
assertSameValue(1, $users->createUserCalls, 'Registration should create the user only after verification.');

$app = new AuthEntryServiceTestApplication();
$users = new FakeUserService();
$users->byEmail['known@example.test'] = makeUser(7, 'known', 'known@example.test');
$mfa = new FakeMfaService();
$mfa->enabled = true;
$svc = makeService($app->session, $users, $mfa);
$result = $svc->beginLogin(new AuthService($app), 'known@example.test');
assertSameValue(AuthEntryService::STATUS_APP_MFA_REQUIRED, $result['status'], 'Existing app-MFA users should be routed to app verification.');

$app = new AuthEntryServiceTestApplication();
$users = new FakeUserService();
$users->byUsername['mfa-user'] = makeUser(8, 'mfa-user', 'mfa-user@example.test');
$mfa = new FakeMfaService();
$mfa->enabled = true;
$auth = new AuthService($app);
$svc = makeService($app->session, $users, $mfa);
$result = $svc->beginLogin($auth, 'mfa-user');
assertSameValue(AuthEntryService::STATUS_APP_MFA_REQUIRED, $result['status'], 'Existing app-MFA users should be found by username and routed to app verification.');
assertSameValue('mfa-user@example.test', $auth->getPendingLoginEmail(), 'Username login should store the account email for the pending MFA session.');

$app = new AuthEntryServiceTestApplication();
$users = new FakeUserService();
$email = new FakeEmailService();
$svc = makeService($app->session, $users, email: $email);
$result = $svc->beginLogin(new AuthService($app), 'not-an-email');
assertSameValue(AuthEntryService::STATUS_VALIDATION_ERROR, $result['status'], 'Unknown non-email login identifiers should be rejected before email delivery.');
assertSameValue([], $email->sent, 'Unknown non-email login identifiers should not try to send an OTP email.');

fwrite(STDOUT, "AuthEntryService tests passed.\n");
