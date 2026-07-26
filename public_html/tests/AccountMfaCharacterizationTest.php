<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\SessionService;
use src\Business\EmailService;
use src\Business\MFATOTPService;
use src\Business\TOTPEmailService;
use src\Controller\Account;
use src\Entity\User;

const MFA_TOTP_DIGITS = 6;
const MFA_TOTP_PERIOD = 30;
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

final class AccountMfaTestSession extends SessionService
{
    public array $values = [];

    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
}

final class AccountMfaTestCsrf extends CsrfService
{
    public int $rotations = 0;

    public function __construct() {}
    public function rotateToken(): string { ++$this->rotations; return 'rotated'; }
}

final class AccountMfaTestService extends MFATOTPService
{
    public array $calls = [];

    public function __construct(public bool $enabled) {}
    public function hasEnabledMfa(int $userId): bool { $this->calls[] = ['hasEnabledMfa', $userId]; return $this->enabled; }
    public function generateSecret(int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): string { $this->calls[] = ['generateSecret']; return 'generated-secret'; }
    public function verifySecret(string $secret, string $code, int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): bool { $this->calls[] = ['verifySecret', $secret, $code]; return true; }
    public function enableMfa(int $userId, string $secret, int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): bool { $this->calls[] = ['enableMfa', $userId, $secret]; return true; }
    public function verifyEnabledSecret(int $userId, string $code): bool { $this->calls[] = ['verifyEnabledSecret', $userId, $code]; return true; }
    public function disableMfa(int $userId): bool { $this->calls[] = ['disableMfa', $userId]; return true; }
}

final class AccountMfaTestEmailTotp extends TOTPEmailService
{
    public array $calls = [];

    public function __construct() {}
    public function generateEmailTOTPForSession(int $userId, string $sessionKey): string { $this->calls[] = ['generate', $userId, $sessionKey]; return '654321'; }
    public function verifyEmailTOTPForSession(int $userId, string $totp, string $sessionKey): bool { $this->calls[] = ['verify', $userId, $totp, $sessionKey]; return true; }
}

final class AccountMfaTestEmail extends EmailService
{
    public array $calls = [];

    public function __construct() {}
    public function sendTOTPEmail(string $toEmail, string $totp): bool { $this->calls[] = [$toEmail, $totp]; return true; }
}

final class AccountMfaTestApplication extends Application
{
    public function __construct(
        public AccountMfaTestSession $session,
        public AccountMfaTestEmailTotp $emailTotp,
        public AccountMfaTestEmail $email
    ) {}

    public function get(string $name): ?object
    {
        return match ($name) {
            'sessionService' => $this->session,
            'totpEmailService' => $this->emailTotp,
            'emailService' => $this->email,
            default => null,
        };
    }
}

final class AccountMfaTestRequest extends Request
{
    public function __construct(private array $fields) {}
    public function post(string $key, $default = null): mixed { return $this->fields[$key] ?? $default; }
}

function assertMfaSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @return array{messages: array, pending: ?string, mfaCalls: array, emailTotpCalls: array, emailCalls: array, csrfRotations: int} */
function runMfaCase(bool $enabled, array $fields): array
{
    $session = new AccountMfaTestSession();
    $session->values[AuthSessionKeys::PENDING_MFA_SECRET] = 'pending-secret';
    $session->values[AuthSessionKeys::MFA_SETUP_EMAIL_SECRET] = 'pending-email-secret';
    $csrf = new AccountMfaTestCsrf();
    $auth = new AuthService($session, $csrf);
    $mfa = new AccountMfaTestService($enabled);
    $emailTotp = new AccountMfaTestEmailTotp();
    $email = new AccountMfaTestEmail();
    $application = new AccountMfaTestApplication($session, $emailTotp, $email);

    $account = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Account::class, 'application'))->setValue($account, $application);
    (new ReflectionProperty(Account::class, 'mfaService'))->setValue($account, $mfa);
    $user = new User(42, 'alice', 'alice@example.test', '127.0.0.1', new DateTimeImmutable(), new DateTimeImmutable(), new DateTimeImmutable());
    (new ReflectionMethod(Account::class, 'handleMfa'))->invoke($account, new AccountMfaTestRequest($fields), $user, $auth);

    return [
        'messages' => (new ReflectionProperty(Account::class, 'accountMessages'))->getValue($account),
        'pending' => $auth->getPendingMfaSecret(),
        'emailPending' => $session->get(AuthSessionKeys::MFA_SETUP_EMAIL_SECRET),
        'mfaCalls' => $mfa->calls,
        'emailTotpCalls' => $emailTotp->calls,
        'emailCalls' => $email->calls,
        'csrfRotations' => $csrf->rotations,
    ];
}

$setupWins = runMfaCase(false, ['submit_mfa_setup' => '1', 'submit_mfa_cancel' => '1']);
assertMfaSame(['errors' => [], 'success' => ['account.mfa-secret-generated', 'account.mfa-email-code-sent']], $setupWins['messages'], 'Setup should win over cancel and report both setup statuses.');
assertMfaSame('pending-secret', $setupWins['pending'], 'Setup should preserve an existing pending secret.');
assertMfaSame('pending-email-secret', $setupWins['emailPending'], 'Setup should not clear pending email verification state.');
assertMfaSame([['hasEnabledMfa', 42]], $setupWins['mfaCalls'], 'Setup with a pending secret should not verify or persist MFA.');
assertMfaSame([['generate', 42, AuthSessionKeys::MFA_SETUP_EMAIL_SECRET]], $setupWins['emailTotpCalls'], 'Setup should generate the email verification code.');
assertMfaSame([['alice@example.test', '654321']], $setupWins['emailCalls'], 'Setup should send the generated email code.');
assertMfaSame(0, $setupWins['csrfRotations'], 'Setup should not rotate CSRF.');

$cancelWins = runMfaCase(false, ['submit_mfa_cancel' => '1', 'submit_mfa_confirm' => '1', 'mfa_code' => '123456', 'mfa_email_code' => '654321']);
assertMfaSame(['errors' => [], 'success' => ['account.mfa-secret-cleared']], $cancelWins['messages'], 'Cancel should win over confirm and report the clear status.');
assertMfaSame(null, $cancelWins['pending'], 'Cancel should clear the pending secret.');
assertMfaSame(null, $cancelWins['emailPending'], 'Cancel should clear pending email verification state.');
assertMfaSame([['hasEnabledMfa', 42]], $cancelWins['mfaCalls'], 'Cancel should not verify or persist MFA.');
assertMfaSame([], $cancelWins['emailTotpCalls'], 'Cancel should not verify an email code.');
assertMfaSame([], $cancelWins['emailCalls'], 'Cancel should not send email.');
assertMfaSame(0, $cancelWins['csrfRotations'], 'Cancel should not rotate CSRF.');

$confirmWins = runMfaCase(false, ['submit_mfa_confirm' => '1', 'submit_mfa_disable' => '1', 'mfa_code' => '123456', 'mfa_email_code' => '654321', 'mfa_disable_code' => '111111']);
assertMfaSame(['errors' => [], 'success' => ['account.mfa-enabled']], $confirmWins['messages'], 'Confirm should win over disable and report enabled.');
assertMfaSame(null, $confirmWins['pending'], 'Successful confirm should clear the pending secret.');
assertMfaSame(null, $confirmWins['emailPending'], 'Successful confirm should clear pending email verification state.');
assertMfaSame([['hasEnabledMfa', 42], ['verifySecret', 'pending-secret', '123456'], ['enableMfa', 42, 'pending-secret']], $confirmWins['mfaCalls'], 'Confirm should verify and enable, but must not disable MFA.');
assertMfaSame([['verify', 42, '654321', AuthSessionKeys::MFA_SETUP_EMAIL_SECRET]], $confirmWins['emailTotpCalls'], 'Confirm should verify the email code.');
assertMfaSame([], $confirmWins['emailCalls'], 'Confirm should not send email.');
assertMfaSame(1, $confirmWins['csrfRotations'], 'Successful confirm should rotate CSRF once.');

$enabledCases = [
    'setup' => [['submit_mfa_setup' => '1'], ['errors' => ['account.mfa-setup-already-enabled'], 'success' => []], [['hasEnabledMfa', 42]], [], [], 0, 'pending-secret', 'pending-email-secret'],
    'cancel' => [['submit_mfa_cancel' => '1'], ['errors' => ['account.mfa-disable-requires-verification'], 'success' => []], [['hasEnabledMfa', 42]], [], [], 0, 'pending-secret', 'pending-email-secret'],
    'confirm' => [['submit_mfa_confirm' => '1', 'mfa_code' => '123456', 'mfa_email_code' => '654321'], ['errors' => ['account.mfa-setup-already-enabled'], 'success' => []], [['hasEnabledMfa', 42]], [], [], 0, 'pending-secret', 'pending-email-secret'],
    'disable' => [['submit_mfa_disable' => '1', 'mfa_disable_code' => '123456'], ['errors' => [], 'success' => ['account.mfa-disabled']], [['hasEnabledMfa', 42], ['verifyEnabledSecret', 42, '123456'], ['disableMfa', 42]], [], [], 1, null, 'pending-email-secret'],
];

foreach ($enabledCases as $name => [$fields, $messages, $mfaCalls, $emailTotpCalls, $emailCalls, $rotations, $pending, $emailPending]) {
    $result = runMfaCase(true, $fields);
    assertMfaSame($messages, $result['messages'], "Enabled plus pending {$name} should report the exact characterized status.");
    assertMfaSame($pending, $result['pending'], "Enabled plus pending {$name} should preserve or clear the pending secret as characterized.");
    assertMfaSame($emailPending, $result['emailPending'], "Enabled plus pending {$name} should preserve or clear pending email state as characterized.");
    assertMfaSame($mfaCalls, $result['mfaCalls'], "Enabled plus pending {$name} should call only its characterized MFA collaborators.");
    assertMfaSame($emailTotpCalls, $result['emailTotpCalls'], "Enabled plus pending {$name} should call only its characterized email-TOTP collaborators.");
    assertMfaSame($emailCalls, $result['emailCalls'], "Enabled plus pending {$name} should not unexpectedly send email.");
    assertMfaSame($rotations, $result['csrfRotations'], "Enabled plus pending {$name} should rotate CSRF only when characterized.");
}

fwrite(STDOUT, "Account MFA characterization tests passed.\n");
