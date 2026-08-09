<?php

declare(strict_types=1);

namespace Twig {
    class Environment
    {
        public static array $lastVars = [];
        public function __construct(mixed $loader = null) {}
        public function render(string $name, array $vars = []): string
        {
            self::$lastVars = $vars;
            $html = '<main data-template="' . $name . '">';
            if ((bool) ($vars['awaitingOtp'] ?? false) === true || (bool) ($vars['UID'] ?? null) === true) {
                $html .= '<form action="/logout"><button>Logout</button></form>';
                if ($vars['awaitingOtp'] ?? false) {
                    $html .= '<input type="email" id="email_reference" value="' . $vars['email'] . '" disabled />';
                }
                $html .= '<input name="totp[]" />';
                $recoveryErrors = $vars['loginRecovery']['errors'] ?? [];
                if ($recoveryErrors !== []) {
                    $html .= '<details data-login-recovery open>' . implode('', $recoveryErrors) . '</details>';
                }
            } else {
                if ($name === 'register.twig') {
                    $html .= '<input name="username" value="' . htmlspecialchars((string) ($vars['registerUsername'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
                }
                $html .= '<input name="email" value="' . htmlspecialchars((string) ($vars['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
            }
            return $html . '</main>';
        }
    }
}
namespace Twig\Loader { class ArrayLoader { public function __construct(array $templates) {} } }
namespace {

defined('APP_BASE') || define('APP_BASE', '');
defined('WEB_ROOT') || define('WEB_ROOT', APP_BASE . '/');
defined('APP_MAX_AGE') || define('APP_MAX_AGE', 7200);

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\CsrfService;
use app\Service\YamlCacheService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthEntryService;
use src\Business\RecoveryFeatureService;
use src\Controller\AuthEntryController;

const AUTHENTICATOR_TOTP_DIGITS = 6;
const AUTHENTICATOR_TOTP_PERIOD = 30;
const REQUEST_URI = '/login';
const REQUEST_METHOD = 'POST';
const SRC_CONTROLLER = 'src\\Controller\\';
const DOC_ROOT = __DIR__ . '/..';
const ENVIRONMENT = 'testing';
const DEVELOPMENT = false;

function __(string $key, array $parameters = []): string
{
    foreach ($parameters as $name => $value) {
        $key .= ' ' . $name . '=' . $value;
    }
    return $key;
}

require_once __DIR__ . '/../app/Container/Container.php';
require_once __DIR__ . '/../app/Container/Application.php';
require_once __DIR__ . '/../app/Http/Superglobal.php';
require_once __DIR__ . '/../app/Http/Request.php';
require_once __DIR__ . '/../app/Http/Response.php';
require_once __DIR__ . '/../app/Http/Router.php';
require_once __DIR__ . '/../app/Service/SessionService.php';
require_once __DIR__ . '/../app/Service/CsrfService.php';
require_once __DIR__ . '/../app/Service/YamlCacheService.php';
require_once __DIR__ . '/../app/Service/AuthSessionKeys.php';
require_once __DIR__ . '/../app/Service/AuthService.php';
require_once __DIR__ . '/../src/Controller/Controller.php';
require_once __DIR__ . '/../src/Controller/AuthEntryController.php';
require_once __DIR__ . '/../src/Business/AuthEntryService.php';
require_once __DIR__ . '/../src/Business/AuthenticationRateLimitBucketDimension.php';
require_once __DIR__ . '/../src/Business/AuthenticationRateLimitAccountIdentifier.php';
require_once __DIR__ . '/../src/Business/AuthenticationRateLimitContext.php';
require_once __DIR__ . '/../src/Business/RecoveryCodeConsumptionResult.php';
require_once __DIR__ . '/../src/Business/RecoveryFeatureService.php';

final class AuthEntryTestSession extends \app\Service\SessionService
{
    public function __construct() {}
    public array $values = ['_IPaddress' => '127.0.0.1'];
    public array $flashes = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return isset($this->values[$key]) === true ? $this->sanitizeValue($this->values[$key]) : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $this->sanitizeValue($value);
    }

    public function remove(string $key): void { unset($this->values[$key]); }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value) === true) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeValue($item);
            }

            return $sanitized;
        }

        return $value;
    }
    public function regenerate(): void {}
    public function flash(string $bag, string $type, string $message): void
    {
        if (in_array($type, ['errors', 'success'], true) === false) {
            throw new \InvalidArgumentException('Unsupported flash message type.');
        }
        $this->flashes[$bag][$type][] = $message;
    }
    public function consumeFlash(string $bag): array
    {
        $messages = $this->flashes[$bag] ?? [];
        unset($this->flashes[$bag]);
        return [
            'errors' => is_array($messages['errors'] ?? null) === true ? $messages['errors'] : [],
            'success' => is_array($messages['success'] ?? null) === true ? $messages['success'] : [],
        ];
    }
}

final class AuthEntryTestTranslation { public function setFile(string $file): void {} }
final class AuthEntryTestRecoveryFeature extends RecoveryFeatureService
{
    public ?int $activeSetId = 99;
    public function __construct() {}
    public function getActiveRecoverySetId(int $userId): ?int { return $this->activeSetId; }
}
final class AuthEntryTestApplication extends Application
{
    public Router $router;
    public AuthEntryTestSession $session;
    public CsrfService $csrf;
    public AuthService $auth;
    public AuthEntryTestRecoveryFeature $recoveryFeature;

    public function __construct()
    {
        $this->router = new Router();
        $this->session = new AuthEntryTestSession();
        $this->csrf = new CsrfService($this->session);
        $this->auth = new AuthService(
            $this->session,
            $this->csrf
        );
        $this->recoveryFeature = new AuthEntryTestRecoveryFeature();
    }

    public function get(string $name): object
    {
        return match ($name) {
            \app\Http\Router::class => $this->router,
            \app\Service\SessionService::class => $this->session,
            \app\Service\AuthService::class => $this->auth,
            \app\Service\TranslationService::class => new AuthEntryTestTranslation(),
            \Twig\Environment::class => new \Twig\Environment(new \Twig\Loader\ArrayLoader(['login.twig' => 'login', 'register.twig' => 'register'])),
            \app\Service\CsrfService::class => $this->csrf,
            \src\Business\RecoveryFeatureService::class => $this->recoveryFeature,
            default => throw new RuntimeException($name . ' is missing.'),
        };
    }
}

final class FakeAuthEntryService extends AuthEntryService
{
    public array $calls = [];
    public array $queue = [];
    public function __construct() {}
    public function beginLogin(AuthService $auth, string $email): array { $this->calls[] = ['beginLogin', $email]; return array_shift($this->queue); }
    public function beginRegistration(AuthService $auth, string $username, string $email): array { $this->calls[] = ['beginRegistration', $username, $email]; return array_shift($this->queue); }
    public function verify(AuthService $auth, string $mode, string $otp): array { $this->calls[] = ['verify', $mode, $otp]; return array_shift($this->queue); }
    public function verifyRecoveryCode(AuthService $auth, string $submittedCode, AuthenticationRateLimitContext $rateLimitContext): array
    {
        $this->calls[] = ['verifyRecoveryCode', $submittedCode];
        return array_shift($this->queue);
    }
}

final class TestAuthEntryController extends AuthEntryController
{
    public function __construct(Application $application, private FakeAuthEntryService $service) { parent::__construct($application); }
    protected function authEntryService(): AuthEntryService { return $this->service; }
}

final class AuthEntryTestRequest extends Request
{
    private array $testPost;
    public function __construct(array $post) { $this->testPost = $post; }
    public function post(string $key, $default = null): mixed { return $this->testPost[$key] ?? $default; }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContainsValue(string $needle, array $haystack, string $message): void
{
    if (in_array($needle, $haystack, true) === false) {
        throw new RuntimeException($message . ' Missing ' . $needle . ' in ' . var_export($haystack, true));
    }
}

function assertResponseContains(Response $response, string $needle, string $message): void
{
    if (str_contains($response->getContent(), $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . $needle . ' in ' . $response->getContent());
    }
}

function assertResponseNotContains(Response $response, string $needle, string $message): void
{
    if (str_contains($response->getContent(), $needle) === true) {
        throw new RuntimeException($message . ' Unexpected ' . $needle . ' in ' . $response->getContent());
    }
}

function runController(string $mode, array $post, array $result): array
{
    $app = new AuthEntryTestApplication();
    $service = new FakeAuthEntryService();
    $service->queue[] = $result;
    $response = (new TestAuthEntryController($app, $service))->handle(new AuthEntryTestRequest($post), $mode);
    return [$response, $app->session->flashes, $service->calls];
}

(new Router())->load(DOC_ROOT . '/src/resources/routes.yaml');
$unsupportedFlashRejected = false;
try {
    (new ReflectionClass(\app\Service\SessionService::class))
        ->newInstanceWithoutConstructor()
        ->flash('login', 'unsupported', 'message');
} catch (InvalidArgumentException) {
    $unsupportedFlashRejected = true;
}
assertSameValue(
    true,
    $unsupportedFlashRejected,
    'Production session flash storage must reject unsupported message types.'
);

[$response, $flashes, $calls] = runController('login', ['submit_login' => '1', 'email' => 'known@example.test'], ['status' => AuthEntryService::STATUS_EMAIL_OTP_SENT]);
assertSameValue(303, $response->getStatusCode(), 'Existing-user login should redirect after sending email OTP.');
assertSameValue([['beginLogin', 'known@example.test']], $calls, 'Login should delegate lookup and OTP work to the service.');
assertContainsValue('otp-email-sent', $flashes['login']['success'] ?? [], 'Existing-user login should flash email OTP instructions.');

[$response, $flashes, $calls] = runController('login', ['submit_login' => '1', 'email' => 'new@example.test'], ['status' => AuthEntryService::STATUS_EMAIL_OTP_SENT]);
assertSameValue([['beginLogin', 'new@example.test']], $calls, 'Unknown-email login should delegate deferred user creation to the service.');
assertContainsValue('otp-email-sent', $flashes['login']['success'] ?? [], 'Unknown-email login should still send email OTP before account creation.');

[$response, $flashes, $calls] = runController('register', ['submit_register' => '1', 'username' => 'alice', 'email' => 'dupe@example.test'], ['status' => AuthEntryService::STATUS_VALIDATION_ERROR, 'error' => 'duplicate-email']);
assertSameValue([['beginRegistration', 'alice', 'dupe@example.test']], $calls, 'Register should delegate duplicate checks to the service.');
assertContainsValue('email-address-already-in-use', $flashes['register']['errors'] ?? [], 'Duplicate registration should map to the duplicate-email flash.');

$app = new AuthEntryTestApplication();
$service = new FakeAuthEntryService();
$service->queue[] = ['status' => AuthEntryService::STATUS_VALIDATION_ERROR, 'error' => 'duplicate-email'];
$response = (new TestAuthEntryController($app, $service))->handle(new AuthEntryTestRequest(['submit_register' => '1', 'username' => 'alice', 'email' => 'dupe@example.test']), 'register');
assertSameValue(['username' => 'alice', 'email' => 'dupe@example.test'], $app->session->values['register.form_values'] ?? null, 'Failed registration should temporarily remember submitted values.');
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(new AuthEntryTestRequest([]), 'login');
assertSameValue(['username' => 'alice', 'email' => 'dupe@example.test'], $app->session->values['register.form_values'] ?? null, 'Visiting login should not consume remembered registration values.');
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(new AuthEntryTestRequest([]), 'register');
assertSameValue('alice', \Twig\Environment::$lastVars['registerUsername'] ?? null, 'Register form should expose remembered username once.');
assertSameValue('dupe@example.test', \Twig\Environment::$lastVars['email'] ?? null, 'Register form should expose remembered email once.');
assertResponseContains($response, 'name="username" value="alice"', 'Register form should pre-fill remembered username.');
assertResponseContains($response, 'name="email" value="dupe@example.test"', 'Register form should pre-fill remembered email.');
assertSameValue(false, array_key_exists('register.form_values', $app->session->values), 'Remembered registration values should be discarded after reading.');
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(new AuthEntryTestRequest([]), 'register');
assertSameValue('', \Twig\Environment::$lastVars['registerUsername'] ?? null, 'Register form should not expose remembered username twice.');
assertSameValue(null, \Twig\Environment::$lastVars['email'] ?? null, 'Register form should not expose remembered email twice.');

$app = new AuthEntryTestApplication();
$service = new FakeAuthEntryService();
$service->queue[] = ['status' => AuthEntryService::STATUS_SEND_ERROR];
(new TestAuthEntryController($app, $service))->handle(new AuthEntryTestRequest(['submit_register' => '1', 'username' => 'send-failed', 'email' => 'failed@example.test']), 'register');
assertSameValue(['username' => 'send-failed', 'email' => 'failed@example.test'], $app->session->values['register.form_values'] ?? null, 'Registration send errors should temporarily remember submitted values.');
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(new AuthEntryTestRequest([]), 'register');
assertResponseContains($response, 'name="username" value="send-failed"', 'Register form should pre-fill the username after a send error.');
assertResponseContains($response, 'name="email" value="failed@example.test"', 'Register form should pre-fill the email after a send error.');
assertSameValue(false, array_key_exists('register.form_values', $app->session->values), 'Send-error registration values should be discarded after reading.');

$app = new AuthEntryTestApplication();
$service = new FakeAuthEntryService();
$service->queue[] = ['status' => AuthEntryService::STATUS_VALIDATION_ERROR, 'error' => 'invalid-username'];
(new TestAuthEntryController($app, $service))->handle(new AuthEntryTestRequest(['submit_register' => '1', 'username' => 'alice" autofocus onfocus="alert(1)', 'email' => 'safe@example.test']), 'register');
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(new AuthEntryTestRequest([]), 'register');
assertResponseContains($response, 'value="alice&quot; autofocus onfocus=&quot;alert(1)"', 'Remembered registration values should be escaped in HTML attributes.');
assertResponseNotContains($response, 'value="alice" autofocus', 'Remembered registration values should not break out of the value attribute.');

[$response, $flashes, $calls] = runController('register', ['submit_register' => '1', 'username' => 'alice', 'email' => 'new@example.test'], ['status' => AuthEntryService::STATUS_EMAIL_OTP_SENT]);
assertContainsValue('login.otp-email-sent', $flashes['register']['success'] ?? [], 'Registration should use email OTP before creating the user.');


$app = new AuthEntryTestApplication();
$app->auth->setPendingLoginEmail('pending@example.test');
$app->auth->setPendingUserId(null);
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(new AuthEntryTestRequest([]), 'register');
assertSameValue(200, $response->getStatusCode(), 'Pending registration OTP page should render successfully.');
assertSameValue(true, \Twig\Environment::$lastVars['awaitingOtp'] ?? null, 'Pending email-only registration should expose awaitingOtp even without a pending user id.');
assertSameValue(null, \Twig\Environment::$lastVars['uUID'] ?? null, 'Pending email-only registration should not require a pending user id.');
assertResponseContains($response, 'name="totp[]"', 'Pending email-only registration should render the OTP step.');
assertResponseContains($response, 'id="email_reference"', 'Pending email-only registration should show the email reference.');
assertResponseContains($response, 'pending@example.test', 'Pending email-only registration should show the pending email address.');
assertResponseContains($response, 'Logout', 'Pending email-only registration should show the logout control.');

$app = new AuthEntryTestApplication();
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(new AuthEntryTestRequest([]), 'login');
assertSameValue(200, $response->getStatusCode(), 'Anonymous login page should render successfully.');
assertResponseNotContains($response, 'Logout', 'Anonymous auth entry pages should not show the logout control.');

[$response, $flashes, $calls] = runController('login', ['submit_login' => '1', 'email' => 'authenticator@example.test'], ['status' => AuthEntryService::STATUS_AUTHENTICATOR_CODE_REQUIRED]);
assertContainsValue('login.authenticator-app-instructions digits=6 period=30', $flashes['login']['success'] ?? [], 'App authenticator should map to app authenticator instructions.');

[$response, $flashes, $calls] = runController('login', ['submit_totp' => '1', 'totp' => ['1','2','3','4','5','6']], ['status' => AuthEntryService::STATUS_AUTHENTICATED, 'jwtToken' => 'jwt']);
assertSameValue(303, $response->getStatusCode(), 'Verify success should redirect to account.');
assertSameValue('Location: /account', $response->getHeaders()[0] ?? null, 'Verify success should target account.');
assertSameValue([['verify', 'login', '123456']], $calls, 'Verify should concatenate OTP digits and delegate JWT issuing to the service.');
assertContainsValue('success-authenticated', $flashes['account']['success'] ?? [], 'Verify success should flash account success.');

$app = new AuthEntryTestApplication();
$app->auth->setPendingLoginEmail('recovery@example.test');
$app->auth->setPendingUserId(42);
$app->auth->setLoginAuthenticatorRequired(true);
$service = new FakeAuthEntryService();
$service->queue[] = [
    'status' => AuthEntryService::STATUS_AUTHENTICATED,
    'remainingCount' => 1,
];
$response = (new TestAuthEntryController($app, $service))->handle(
    new AuthEntryTestRequest([
        'submit_recovery_code' => '1',
        'recovery_code' => 'ABCDE-FGHJK-MNPQR-STUVW',
    ]),
    'login'
);
assertSameValue(303, $response->getStatusCode(), 'Recovery-code fallback should use the normal authenticated redirect.');
assertSameValue(
    [['verifyRecoveryCode', 'ABCDE-FGHJK-MNPQR-STUVW']],
    $service->calls,
    'The recovery-code form must delegate to the typed recovery verification path.'
);
assertContainsValue(
    'login.recovery-code-one-remaining count=1',
    $app->session->flashes['account']['success'] ?? [],
    'One remaining code must produce the prominent replacement recommendation.'
);

$app = new AuthEntryTestApplication();
$app->auth->setPendingLoginEmail('recovery@example.test');
$app->auth->setPendingUserId(42);
$app->auth->setLoginAuthenticatorRequired(true);
$service = new FakeAuthEntryService();
$service->queue[] = ['status' => AuthEntryService::STATUS_INVALID_RECOVERY_CODE];
$response = (new TestAuthEntryController($app, $service))->handle(
    new AuthEntryTestRequest([
        'submit_recovery_code' => '1',
        'recovery_code' => 'ABCDE-FGHJK-MNPQR-STUVW',
    ]),
    'login'
);
assertSameValue(303, $response->getStatusCode(), 'Invalid recovery codes should retain PRG behavior.');
assertContainsValue(
    'login.recovery-code-invalid',
    $app->session->flashes['login.recovery']['errors'] ?? [],
    'Invalid recovery codes must use a recovery-specific inline error.'
);
assertSameValue(
    [],
    $app->session->flashes['login']['errors'] ?? [],
    'Invalid recovery codes must not be presented as generic authenticator OTP failures.'
);
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(
    new AuthEntryTestRequest([]),
    'login'
);
assertResponseContains(
    $response,
    '<details data-login-recovery open>login.recovery-code-invalid</details>',
    'The redirected login GET must show the recovery error inside an open recovery subsection.'
);
assertSameValue(
    false,
    isset($app->session->flashes['login.recovery']),
    'Recovery feedback must be consumed after the redirected login GET.'
);
$response = (new TestAuthEntryController($app, new FakeAuthEntryService()))->handle(
    new AuthEntryTestRequest([]),
    'login'
);
assertResponseNotContains(
    $response,
    'login.recovery-code-invalid',
    'Recovery feedback must not be displayed twice.'
);

$app = new AuthEntryTestApplication();
$app->auth->setPendingLoginEmail('recovery@example.test');
$app->auth->setPendingUserId(42);
$app->auth->setLoginAuthenticatorRequired(true);
$app->recoveryFeature->activeSetId = null;
$service = new FakeAuthEntryService();
$response = (new TestAuthEntryController($app, $service))->handle(new AuthEntryTestRequest([]), 'login');
assertSameValue(
    false,
    \Twig\Environment::$lastVars['loginRecoveryAvailable'] ?? null,
    'Recovery-code fallback must not be offered when the account has no active recovery-code set.'
);
$response = (new TestAuthEntryController($app, $service))->handle(
    new AuthEntryTestRequest([
        'submit_recovery_code' => '1',
        'recovery_code' => 'ABCDE-FGHJK-MNPQR-STUVW',
    ]),
    'login'
);
assertSameValue([], $service->calls, 'Unavailable recovery-code fallback must not attempt credential consumption.');
assertContainsValue(
    'login.recovery-code-unavailable',
    $app->session->flashes['login']['errors'] ?? [],
    'A stale recovery-code submission without an active set should explain that the fallback is unavailable.'
);

fwrite(STDOUT, "AuthEntryController tests passed.\n");

}
