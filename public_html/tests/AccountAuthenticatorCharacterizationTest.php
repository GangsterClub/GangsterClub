<?php

declare(strict_types=1);

use app\Http\Request;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\FlashBag;
use app\Service\SessionService;
use app\Service\TranslationService;
use src\Business\AuthenticatorTOTPService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\EmailService;
use src\Business\EmailTOTPPurpose;
use src\Business\EmailTOTPService;
use src\Business\IssuedEmailTOTP;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;
use src\Controller\Account;
use src\Data\Repository\UserEmailChangeRepository;
use src\Data\Repository\UserRepository;
use src\Entity\User;
use src\Identity\AccountSecurity\Application\UpdateTwoStepPreference;
use src\Identity\AccountSecurity\Http\AccountSecurityFormHandler;
use src\Identity\Profile\Application\ProfileManagement;
use src\Identity\Profile\Http\EmailChangeVerification;
use src\Identity\Profile\Http\ProfileFormHandler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

require_once __DIR__ . '/../vendor/autoload.php';

defined('APP_BASE') || define('APP_BASE', '/game');
defined('WEB_ROOT') || define('WEB_ROOT', APP_BASE . '/');
defined('APP_MAX_AGE') || define('APP_MAX_AGE', 7200);
defined('REQUEST_METHOD') || define('REQUEST_METHOD', 'POST');
defined('REQUEST_URI') || define('REQUEST_URI', '/account');
defined('AUTHENTICATOR_TOTP_DIGITS') || define('AUTHENTICATOR_TOTP_DIGITS', 6);
defined('AUTHENTICATOR_TOTP_PERIOD') || define('AUTHENTICATOR_TOTP_PERIOD', 30);
defined('TOTP_DIGITS') || define('TOTP_DIGITS', 6);
defined('TOTP_PERIOD') || define('TOTP_PERIOD', 300);

final class AccountSecurityFormTestRequest extends Request
{
    public function __construct(
        private readonly array $fields,
        private readonly string $method = 'POST'
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }
}

final class AccountSecurityFormTestTranslation extends TranslationService
{
    public function __construct()
    {
    }

    public function get(string $key, array $replacements = [], bool $useFallback = true): string
    {
        return $key;
    }
}

final class AccountSecurityFormTestAuthenticator extends AuthenticatorTOTPService
{
    public array $calls = [];

    public function __construct(public bool $enabled)
    {
    }

    public function hasEnabledAuthenticator(int $userId): bool
    {
        $this->calls[] = $userId;
        return $this->enabled;
    }
}

final class AccountSecurityFormTestUsers extends UserService
{
    public array $updates = [];
    public bool $succeeds = true;
    public ?User $user = null;

    public function __construct()
    {
    }

    public function setTwoStepVerificationRequired(int $userId, bool $required): bool
    {
        $this->updates[] = [$userId, $required];
        return $this->succeeds;
    }

    public function getUserById(int $userId): ?User
    {
        return $this->user;
    }
}

final class AccountSecurityFormTestSession extends SessionService
{
    public array $values = [];

    public function __construct()
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }
}

final class AccountSecurityFormTestCsrf extends CsrfService
{
    public int $rotations = 0;

    public function __construct()
    {
    }

    public function rotateToken(): string
    {
        ++$this->rotations;
        return 'rotated';
    }
}

final class AccountSecurityFormTestEmailTotp extends EmailTOTPService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function issue(
        int $userId,
        EmailTOTPPurpose $purpose,
        AuthenticationRateLimitContext $context
    ): IssuedEmailTOTP {
        $this->calls[] = ['issue', $userId, $purpose];
        return new IssuedEmailTOTP(1, '654321');
    }

    public function verify(
        int $userId,
        EmailTOTPPurpose $purpose,
        string $submittedCode,
        AuthenticationRateLimitContext $context
    ): bool {
        $this->calls[] = ['verify', $userId, $purpose, $submittedCode];
        return true;
    }
}

final class AccountSecurityFormTestEmail extends EmailService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function sendEmailTOTP(string $toEmail, string $totp): bool
    {
        $this->calls[] = ['sendEmailTOTP', $toEmail, $totp];
        return true;
    }

    public function sendEmailChangeVerification(
        string $toEmail,
        string $newEmail,
        string $verificationUrl
    ): bool {
        $this->calls[] = ['sendEmailChangeVerification', $toEmail, $newEmail, $verificationUrl];
        return true;
    }
}

final class AccountSecurityFormTestRecovery extends RecoveryFeatureService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function getActiveRecoverySetId(int $userId): ?int
    {
        $this->calls[] = ['getActiveRecoverySetId', $userId];
        return null;
    }

    public function disableAuthenticatorAndRecoveryCodes(int $userId): bool
    {
        $this->calls[] = ['disableAuthenticatorAndRecoveryCodes', $userId];
        return true;
    }
}

final class AccountSecurityFormTestUserRepository extends UserRepository
{
    public function __construct()
    {
    }
}

final class AccountSecurityFormTestEmailChangeRepository extends UserEmailChangeRepository
{
    public function __construct()
    {
    }
}

final class AccountSecurityFormTestApplication extends \app\Container\Application
{
    public array $resolved = [];

    public function __construct()
    {
    }

    public function get(string $name): object
    {
        $this->resolved[] = $name;
        return parent::get($name);
    }
}

function assertAccountSecurityFormSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function accountSecurityFormUser(): User
{
    $now = new DateTimeImmutable();
    return new User(42, 'alice', 'alice@example.test', '127.0.0.1', $now, $now, $now);
}

$authenticator = new AccountSecurityFormTestAuthenticator(false);
$users = new AccountSecurityFormTestUsers();
$handler = new AccountSecurityFormHandler(
    new UpdateTwoStepPreference($authenticator, $users),
    new AccountSecurityFormTestTranslation()
);
$user = accountSecurityFormUser();
$users->user = $user;

foreach ([
    ['submit_authenticator_setup' => '1'],
    ['submit_authenticator_cancel' => '1'],
    ['submit_authenticator_confirm' => '1'],
] as $fields) {
    assertAccountSecurityFormSame(
        ['errors' => ['account.authenticator-flow-required'], 'success' => []],
        $handler->handle(new AccountSecurityFormTestRequest($fields), $user),
        'Legacy authenticator actions must remain blocked by the staged security flow.'
    );
}
assertAccountSecurityFormSame([], $authenticator->calls, 'Legacy actions must not inspect the authenticator.');
assertAccountSecurityFormSame([], $users->updates, 'Legacy actions must not alter two-step preferences.');

assertAccountSecurityFormSame(
    ['errors' => ['account.two-step-requires-authenticator'], 'success' => []],
    $handler->handle(new AccountSecurityFormTestRequest([
        'submit_two_step' => '1',
        'require_two_step_verification' => '1',
    ]), $user),
    'Two-step verification must retain its authenticator prerequisite message.'
);

$authenticator->enabled = true;
$users->succeeds = false;
assertAccountSecurityFormSame(
    ['errors' => ['account.two-step-update-error'], 'success' => []],
    $handler->handle(new AccountSecurityFormTestRequest([
        'submit_two_step' => '1',
        'require_two_step_verification' => '1',
    ]), $user),
    'Preference persistence errors must retain their Account message.'
);

$users->succeeds = true;
assertAccountSecurityFormSame(
    ['errors' => [], 'success' => ['account.two-step-disabled']],
    $handler->handle(new AccountSecurityFormTestRequest(['submit_two_step' => '1']), $user),
    'An unchecked preference must retain the disabled success message.'
);

assertAccountSecurityFormSame(
    ['errors' => ['account.authenticator-flow-required'], 'success' => ['account.two-step-enabled']],
    $handler->handle(new AccountSecurityFormTestRequest([
        'submit_authenticator_setup' => '1',
        'submit_two_step' => '1',
        'require_two_step_verification' => '1',
    ]), $user),
    'Legacy rejection must precede the independently processed preference result.'
);

(new Router())->load(__DIR__ . '/../src/resources/routes.yaml');
$authenticator->calls = [];
$users->updates = [];
$session = new AccountSecurityFormTestSession();
$session->values[AuthSessionKeys::AUTHENTICATED_USER_ID] = $user->getId();
$session->values[AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET] = 'pending-secret';
$session->values[AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET] = 'pending-email-secret';
$csrf = new AccountSecurityFormTestCsrf();
$auth = new AuthService($session, $csrf);
$emailTotp = new AccountSecurityFormTestEmailTotp();
$email = new AccountSecurityFormTestEmail();
$recovery = new AccountSecurityFormTestRecovery();
$profile = new ProfileManagement(
    new AccountSecurityFormTestUserRepository(),
    new AccountSecurityFormTestEmailChangeRepository(),
    $email
);
$translation = new AccountSecurityFormTestTranslation();
$twig = new Environment(new ArrayLoader(['account.twig' => 'unused']));
$application = new AccountSecurityFormTestApplication();
$application->set(Environment::class, $twig);
$application->set(TranslationService::class, $translation);
$application->set(SessionService::class, $session);
$application->set(FlashBag::class, new FlashBag($session));
$application->set(AuthService::class, $auth);
$application->set(UserService::class, $users);
$application->set(AuthenticatorTOTPService::class, $authenticator);
$application->set(RecoveryFeatureService::class, $recovery);
$application->set(EmailTOTPService::class, $emailTotp);
$application->set(EmailService::class, $email);
$application->set(ProfileFormHandler::class, new ProfileFormHandler($profile, $users, $translation));
$application->set(EmailChangeVerification::class, new EmailChangeVerification($profile, $translation, $twig));
$application->set(AccountSecurityFormHandler::class, $handler);

$response = (new Account($application))(new AccountSecurityFormTestRequest([
    'submit_authenticator_confirm' => '1',
    'authenticator_code' => '123456',
    'authenticator_email_code' => '654321',
]));
assertAccountSecurityFormSame(303, $response->getStatusCode(), 'Rejected legacy Account submissions must retain PRG.');
assertAccountSecurityFormSame(
    'pending-secret',
    $session->get(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET),
    'Rejected legacy submissions must preserve the pending authenticator secret.'
);
assertAccountSecurityFormSame(
    'pending-email-secret',
    $session->get(AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET),
    'Rejected legacy submissions must preserve pending email-verification state.'
);
assertAccountSecurityFormSame(0, $csrf->rotations, 'Rejected legacy submissions must not rotate CSRF.');
assertAccountSecurityFormSame([], $authenticator->calls, 'Rejected legacy submissions must not inspect, verify, or persist an authenticator.');
assertAccountSecurityFormSame([], $emailTotp->calls, 'Rejected legacy submissions must not issue or verify email codes.');
assertAccountSecurityFormSame([], $email->calls, 'Rejected legacy submissions must not send email.');
assertAccountSecurityFormSame([], $recovery->calls, 'Rejected legacy submissions must not inspect or modify recovery-code state.');
assertAccountSecurityFormSame(
    false,
    in_array(EmailTOTPService::class, $application->resolved, true),
    'Rejected legacy submissions must not resolve the email verification service.'
);
assertAccountSecurityFormSame(
    false,
    in_array(EmailService::class, $application->resolved, true),
    'Rejected legacy submissions must not resolve the email delivery service.'
);

fwrite(STDOUT, "Account security form characterization tests passed.\n");
