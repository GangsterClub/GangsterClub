<?php

declare(strict_types=1);

use app\Service\AuthService;
use app\Service\CsrfService;
use app\Service\JWTService;
use src\Business\AuthEntryService;
use src\Business\EmailService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailTOTPService;
use src\Business\TOTPService;
use src\Business\UserService;
use src\Entity\User;

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;
const AUTHENTICATOR_TOTP_DIGITS = 6;
const AUTHENTICATOR_TOTP_PERIOD = 30;

require_once __DIR__ . '/../app/Container/Container.php';
require_once __DIR__ . '/../app/Container/Application.php';
require_once __DIR__ . '/../app/Service/SessionService.php';
require_once __DIR__ . '/../app/Service/CsrfService.php';
require_once __DIR__ . '/../app/Service/AuthSessionKeys.php';
require_once __DIR__ . '/../app/Service/AuthService.php';
require_once __DIR__ . '/../app/Service/JWTService.php';
require_once __DIR__ . '/../src/Entity/User.php';
require_once __DIR__ . '/../src/Business/UserService.php';
require_once __DIR__ . '/../src/Business/TOTPService.php';
require_once __DIR__ . '/../src/Business/AuthenticationRateLimitAccountIdentifier.php';
require_once __DIR__ . '/../src/Business/AuthenticationRateLimitBucketDimension.php';
require_once __DIR__ . '/../src/Business/AuthenticationRateLimitContext.php';
require_once __DIR__ . '/../src/Business/AuthenticationRateLimitPurpose.php';
require_once __DIR__ . '/../src/Business/EmailTOTPPurpose.php';
require_once __DIR__ . '/../src/Business/IssuedEmailTOTP.php';
require_once __DIR__ . '/../src/Business/EmailTOTPService.php';
require_once __DIR__ . '/../src/Business/AuthenticatorTOTPService.php';
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
final class FakeAuthenticatorService extends AuthenticatorTOTPService { public bool $enabled=false; public bool $valid=false; public function __construct() {} public function hasEnabledAuthenticator(int $userId): bool { return $this->enabled; } public function verifyCode(int $userId, string $code): bool { return $this->valid; } }
final class FakeEmailTOTPService extends EmailTOTPService {
    public bool $valid=true;
    public bool $cancelled=false;
    public function __construct() {}
    public function issue(int $userId, \src\Business\EmailTOTPPurpose $purpose, \src\Business\AuthenticationRateLimitContext $context): \src\Business\IssuedEmailTOTP { return new \src\Business\IssuedEmailTOTP(1, '111111'); }
    public function verify(int $userId, \src\Business\EmailTOTPPurpose $purpose, string $totp, \src\Business\AuthenticationRateLimitContext $context): bool { return $this->valid; }
    public function cancelIssued(\src\Business\IssuedEmailTOTP $issued): void { $this->cancelled = true; }
}
final class FakeTotpService extends TOTPService { public function generateSecret(int $digits = TOTP_DIGITS, int $period = TOTP_PERIOD): string { return 'secret'; } public function generateTOTP(?string $secret = null, ?int $digits = AUTHENTICATOR_TOTP_DIGITS, ?int $period = AUTHENTICATOR_TOTP_PERIOD): string { return '222222'; } public function verifyTOTP(string $secret, string $totp, int $digits = TOTP_DIGITS, int $period = TOTP_PERIOD): bool { return $secret === 'secret' && $totp === '222222'; } }
final class FakeEmailService extends EmailService { public array $sent=[]; public bool $succeeds=true; public function __construct() {} public function sendEmailTOTP(string $toEmail, string $totp): bool { $this->sent[] = [$toEmail, $totp]; return $this->succeeds; } }
function makeService(
    AuthEntryServiceTestSession $session,
    FakeUserService $users,
    ?FakeAuthenticatorService $authenticator = null,
    ?FakeEmailTOTPService $emailTotp = null,
    ?FakeTotpService $totp = null,
    ?FakeEmailService $email = null,
): AuthEntryService {
    return new AuthEntryService(
        $users,
        $authenticator ?? new FakeAuthenticatorService(),
        $emailTotp ?? new FakeEmailTOTPService(),
        $totp ?? new FakeTotpService(),
        $email ?? new FakeEmailService(),
        $session
    );
}
function makeUser(int $id, string $username, string $email, bool $twoStepRequired = false): User { return new User($id, $username, $email, '127.0.0.1', new DateTime(), new DateTime(), new DateTime('0000-00-00 00:00:00'), $twoStepRequired); }
function assertSameValue(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) { throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }

$session = new AuthEntryServiceTestSession();
$users = new FakeUserService();
$svc = makeService($session, $users);
$auth = new AuthService($session, new CsrfService($session));
$result = $svc->beginLogin($auth, 'new@example.test');
assertSameValue(AuthEntryService::STATUS_EMAIL_OTP_SENT, $result['status'], 'Unknown login should send an email-only OTP.');
assertSameValue(0, $users->createByEmailCalls, 'Unknown login must not create the user before TOTP verification.');
$result = $svc->verify($auth, AuthEntryService::MODE_LOGIN, '222222');
assertSameValue(AuthEntryService::STATUS_AUTHENTICATED, $result['status'], 'Unknown login should authenticate after first email TOTP verification.');
assertSameValue(1, $users->createByEmailCalls, 'Unknown login should create by email only after verification.');

$session = new AuthEntryServiceTestSession();
$users = new FakeUserService();
$svc = makeService($session, $users);
$auth = new AuthService($session, new CsrfService($session));
$result = $svc->beginRegistration($auth, 'alice', 'alice@example.test');
assertSameValue(AuthEntryService::STATUS_EMAIL_OTP_SENT, $result['status'], 'Registration should send an email-only OTP.');
assertSameValue(0, $users->createUserCalls, 'Registration must not create the user before TOTP verification.');
$result = $svc->verify($auth, AuthEntryService::MODE_REGISTER, '222222');
assertSameValue(AuthEntryService::STATUS_AUTHENTICATED, $result['status'], 'Registration should authenticate after email TOTP verification.');
assertSameValue(1, $users->createUserCalls, 'Registration should create the user only after verification.');

$session = new AuthEntryServiceTestSession();
$users = new FakeUserService();
$users->byEmail['known@example.test'] = makeUser(7, 'known', 'known@example.test');
$authenticator = new FakeAuthenticatorService();
$authenticator->enabled = true;
$svc = makeService($session, $users, $authenticator);
$result = $svc->beginLogin(new AuthService($session, new CsrfService($session)), 'known@example.test');
assertSameValue(AuthEntryService::STATUS_AUTHENTICATOR_CODE_REQUIRED, $result['status'], 'Existing app-authenticator users should be routed to app verification.');

$session = new AuthEntryServiceTestSession();
$users = new FakeUserService();
$users->byEmail['secure@example.test'] = makeUser(8, 'secure', 'secure@example.test', true);
$authenticator = new FakeAuthenticatorService();
$authenticator->enabled = true;
$auth = new AuthService($session, new CsrfService($session));
$svc = makeService($session, $users, $authenticator);
$result = $svc->beginLogin($auth, 'secure@example.test');
assertSameValue(AuthEntryService::STATUS_EMAIL_OTP_SENT, $result['status'], 'Two-step login should request email verification first.');
$result = $svc->verify($auth, AuthEntryService::MODE_LOGIN, '111111');
assertSameValue(AuthEntryService::STATUS_AUTHENTICATOR_CODE_REQUIRED, $result['status'], 'Email verification should advance to authenticator verification.');
assertSameValue(null, $auth->getAuthenticatedUserId(), 'Email verification alone must not authenticate a two-step account.');
$authenticator->valid = true;
$result = $svc->verify($auth, AuthEntryService::MODE_LOGIN, '222222');
assertSameValue(AuthEntryService::STATUS_AUTHENTICATED, $result['status'], 'Authenticator verification should complete two-step login.');
assertSameValue(8, $auth->getAuthenticatedUserId(), 'Both factors should authenticate the expected user.');

$session = new AuthEntryServiceTestSession();
$users = new FakeUserService();
$users->byEmail['mail-failure@example.test'] = makeUser(9, 'mail-failure', 'mail-failure@example.test');
$emailTotp = new FakeEmailTOTPService();
$email = new FakeEmailService();
$email->succeeds = false;
$result = makeService($session, $users, null, $emailTotp, null, $email)->beginLogin(new AuthService($session, new CsrfService($session)), 'mail-failure@example.test');
assertSameValue(AuthEntryService::STATUS_SEND_ERROR, $result['status'], 'Mail failure should be reported.');
assertSameValue(true, $emailTotp->cancelled, 'Mail failure must cancel the newly issued challenge.');

fwrite(STDOUT, "AuthEntryService tests passed.\n");
