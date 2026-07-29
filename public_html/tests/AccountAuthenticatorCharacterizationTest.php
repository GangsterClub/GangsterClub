<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\SessionService;
use src\Business\EmailService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailTOTPService;
use src\Controller\Account;
use src\Entity\User;

const AUTHENTICATOR_TOTP_DIGITS = 6;
const AUTHENTICATOR_TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_PERIOD = 300;

function __(string $key, array $parameters = []): string
{
    return $key;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = ['app\\' => __DIR__ . '/../app/', 'src\\' => __DIR__ . '/../src/'];
    foreach ($prefixes as $prefix => $baseDirectory) {
        if (str_starts_with($class, $prefix) === true) {
            $file = $baseDirectory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file) === true) {
                require $file;
            }
        }
    }
});

final class AccountAuthenticatorTestSession extends SessionService
{
    public array $values = [];

    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
}

final class AccountAuthenticatorTestCsrf extends CsrfService
{
    public int $rotations = 0;

    public function __construct() {}
    public function rotateToken(): string { ++$this->rotations; return 'rotated'; }
}

final class AccountAuthenticatorTestService extends AuthenticatorTOTPService
{
    public array $calls = [];

    public function __construct(public bool $enabled) {}
    public function hasEnabledAuthenticator(int $userId): bool { $this->calls[] = ['hasEnabledAuthenticator', $userId]; return $this->enabled; }
    public function generateSecret(int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): string { $this->calls[] = ['generateSecret']; return 'generated-secret'; }
    public function verifySecret(string $secret, string $code, int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): bool { $this->calls[] = ['verifySecret', $secret, $code]; return true; }
    public function enableAuthenticator(int $userId, string $secret, int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): bool { $this->calls[] = ['enableAuthenticator', $userId, $secret]; return true; }
    public function verifyEnabledSecret(int $userId, string $code): bool { $this->calls[] = ['verifyEnabledSecret', $userId, $code]; return true; }
    public function disableAuthenticator(int $userId): bool { $this->calls[] = ['disableAuthenticator', $userId]; return true; }
}

final class AccountAuthenticatorTestEmailTOTP extends EmailTOTPService
{
    public array $calls = [];

    public function __construct() {}
    public function generateEmailTOTPForSession(int $userId, string $sessionKey): string { $this->calls[] = ['generate', $userId, $sessionKey]; return '654321'; }
    public function verifyEmailTOTPForSession(int $userId, string $totp, string $sessionKey): bool { $this->calls[] = ['verify', $userId, $totp, $sessionKey]; return true; }
}

final class AccountAuthenticatorTestEmail extends EmailService
{
    public array $calls = [];

    public function __construct() {}
    public function sendEmailTOTP(string $toEmail, string $totp): bool { $this->calls[] = [$toEmail, $totp]; return true; }
}

final class AccountAuthenticatorTestApplication extends Application
{
    public function __construct(
        public AccountAuthenticatorTestSession $session,
        public AccountAuthenticatorTestEmailTOTP $emailTotp,
        public AccountAuthenticatorTestEmail $email
    ) {}

    public function get(string $name): ?object
    {
        return match ($name) {
            'sessionService' => $this->session,
            'emailTotpService' => $this->emailTotp,
            'emailService' => $this->email,
            default => null,
        };
    }
}

final class AccountAuthenticatorTestRequest extends Request
{
    public function __construct(private array $fields) {}
    public function post(string $key, $default = null): mixed { return $this->fields[$key] ?? $default; }
}

function assertAuthenticatorSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @return array{messages: array, pending: ?string, authenticatorCalls: array, emailTotpCalls: array, emailCalls: array, csrfRotations: int} */
function runAuthenticatorCase(bool $enabled, array $fields): array
{
    $session = new AccountAuthenticatorTestSession();
    $session->values[AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET] = 'pending-secret';
    $session->values[AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET] = 'pending-email-secret';
    $csrf = new AccountAuthenticatorTestCsrf();
    $auth = new AuthService($session, $csrf);
    $authenticator = new AccountAuthenticatorTestService($enabled);
    $emailTotp = new AccountAuthenticatorTestEmailTOTP();
    $email = new AccountAuthenticatorTestEmail();
    $application = new AccountAuthenticatorTestApplication($session, $emailTotp, $email);

    $account = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Account::class, 'application'))->setValue($account, $application);
    (new ReflectionProperty(Account::class, 'authenticatorService'))->setValue($account, $authenticator);
    $user = new User(42, 'alice', 'alice@example.test', '127.0.0.1', new DateTimeImmutable(), new DateTimeImmutable(), new DateTimeImmutable());
    (new ReflectionMethod(Account::class, 'handleAuthenticator'))->invoke($account, new AccountAuthenticatorTestRequest($fields), $user, $auth);

    return [
        'messages' => (new ReflectionProperty(Account::class, 'accountMessages'))->getValue($account),
        'pending' => $auth->getPendingAuthenticatorSecret(),
        'emailPending' => $session->get(AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET),
        'authenticatorCalls' => $authenticator->calls,
        'emailTotpCalls' => $emailTotp->calls,
        'emailCalls' => $email->calls,
        'csrfRotations' => $csrf->rotations,
    ];
}

$setupWins = runAuthenticatorCase(false, ['submit_authenticator_setup' => '1', 'submit_authenticator_cancel' => '1']);
assertAuthenticatorSame(['errors' => [], 'success' => ['account.authenticator-secret-generated', 'account.authenticator-email-code-sent']], $setupWins['messages'], 'Setup should win over cancel and report both setup statuses.');
assertAuthenticatorSame('pending-secret', $setupWins['pending'], 'Setup should preserve an existing pending secret.');
assertAuthenticatorSame('pending-email-secret', $setupWins['emailPending'], 'Setup should not clear pending email verification state.');
assertAuthenticatorSame([['hasEnabledAuthenticator', 42]], $setupWins['authenticatorCalls'], 'Setup with a pending secret should not verify or persist authenticator.');
assertAuthenticatorSame([['generate', 42, AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET]], $setupWins['emailTotpCalls'], 'Setup should generate the email verification code.');
assertAuthenticatorSame([['alice@example.test', '654321']], $setupWins['emailCalls'], 'Setup should send the generated email code.');
assertAuthenticatorSame(0, $setupWins['csrfRotations'], 'Setup should not rotate CSRF.');

$cancelWins = runAuthenticatorCase(false, ['submit_authenticator_cancel' => '1', 'submit_authenticator_confirm' => '1', 'authenticator_code' => '123456', 'authenticator_email_code' => '654321']);
assertAuthenticatorSame(['errors' => [], 'success' => ['account.authenticator-secret-cleared']], $cancelWins['messages'], 'Cancel should win over confirm and report the clear status.');
assertAuthenticatorSame(null, $cancelWins['pending'], 'Cancel should clear the pending secret.');
assertAuthenticatorSame(null, $cancelWins['emailPending'], 'Cancel should clear pending email verification state.');
assertAuthenticatorSame([['hasEnabledAuthenticator', 42]], $cancelWins['authenticatorCalls'], 'Cancel should not verify or persist authenticator.');
assertAuthenticatorSame([], $cancelWins['emailTotpCalls'], 'Cancel should not verify an email code.');
assertAuthenticatorSame([], $cancelWins['emailCalls'], 'Cancel should not send email.');
assertAuthenticatorSame(0, $cancelWins['csrfRotations'], 'Cancel should not rotate CSRF.');

$confirmWins = runAuthenticatorCase(false, ['submit_authenticator_confirm' => '1', 'submit_authenticator_disable' => '1', 'authenticator_code' => '123456', 'authenticator_email_code' => '654321', 'authenticator_disable_code' => '111111']);
assertAuthenticatorSame(['errors' => [], 'success' => ['account.authenticator-enabled']], $confirmWins['messages'], 'Confirm should win over disable and report enabled.');
assertAuthenticatorSame(null, $confirmWins['pending'], 'Successful confirm should clear the pending secret.');
assertAuthenticatorSame(null, $confirmWins['emailPending'], 'Successful confirm should clear pending email verification state.');
assertAuthenticatorSame([['hasEnabledAuthenticator', 42], ['verifySecret', 'pending-secret', '123456'], ['enableAuthenticator', 42, 'pending-secret']], $confirmWins['authenticatorCalls'], 'Confirm should verify and enable, but must not disable authenticator.');
assertAuthenticatorSame([['verify', 42, '654321', AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET]], $confirmWins['emailTotpCalls'], 'Confirm should verify the email code.');
assertAuthenticatorSame([], $confirmWins['emailCalls'], 'Confirm should not send email.');
assertAuthenticatorSame(1, $confirmWins['csrfRotations'], 'Successful confirm should rotate CSRF once.');

$enabledCases = [
    'setup' => [['submit_authenticator_setup' => '1'], ['errors' => ['account.authenticator-setup-already-enabled'], 'success' => []], [['hasEnabledAuthenticator', 42]], [], [], 0, 'pending-secret', 'pending-email-secret'],
    'cancel' => [['submit_authenticator_cancel' => '1'], ['errors' => ['account.authenticator-disable-requires-verification'], 'success' => []], [['hasEnabledAuthenticator', 42]], [], [], 0, 'pending-secret', 'pending-email-secret'],
    'confirm' => [['submit_authenticator_confirm' => '1', 'authenticator_code' => '123456', 'authenticator_email_code' => '654321'], ['errors' => ['account.authenticator-setup-already-enabled'], 'success' => []], [['hasEnabledAuthenticator', 42]], [], [], 0, 'pending-secret', 'pending-email-secret'],
    'disable' => [['submit_authenticator_disable' => '1', 'authenticator_disable_code' => '123456'], ['errors' => [], 'success' => ['account.authenticator-disabled']], [['hasEnabledAuthenticator', 42], ['verifyEnabledSecret', 42, '123456'], ['disableAuthenticator', 42]], [], [], 1, null, 'pending-email-secret'],
];

foreach ($enabledCases as $name => [$fields, $messages, $authenticatorCalls, $emailTotpCalls, $emailCalls, $rotations, $pending, $emailPending]) {
    $result = runAuthenticatorCase(true, $fields);
    assertAuthenticatorSame($messages, $result['messages'], "Enabled plus pending {$name} should report the exact characterized status.");
    assertAuthenticatorSame($pending, $result['pending'], "Enabled plus pending {$name} should preserve or clear the pending secret as characterized.");
    assertAuthenticatorSame($emailPending, $result['emailPending'], "Enabled plus pending {$name} should preserve or clear pending email state as characterized.");
    assertAuthenticatorSame($authenticatorCalls, $result['authenticatorCalls'], "Enabled plus pending {$name} should call only its characterized authenticator collaborators.");
    assertAuthenticatorSame($emailTotpCalls, $result['emailTotpCalls'], "Enabled plus pending {$name} should call only its characterized email-TOTP collaborators.");
    assertAuthenticatorSame($emailCalls, $result['emailCalls'], "Enabled plus pending {$name} should not unexpectedly send email.");
    assertAuthenticatorSame($rotations, $result['csrfRotations'], "Enabled plus pending {$name} should rotate CSRF only when characterized.");
}

fwrite(STDOUT, "Account authenticator characterization tests passed.\n");
