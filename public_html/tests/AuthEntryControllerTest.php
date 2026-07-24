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
            if (($vars['awaitingOtp'] ?? false) || ($vars['UID'] ?? null)) {
                $html .= '<form action="/logout"><button>Logout</button></form>';
                if ($vars['awaitingOtp'] ?? false) {
                    $html .= '<input type="email" id="email_reference" value="' . $vars['email'] . '" disabled />';
                }
                $html .= '<input name="totp[]" />';
            } else {
                $html .= '<input name="email" />';
            }
            return $html . '</main>';
        }
    }
}
namespace Twig\Loader { class ArrayLoader { public function __construct(array $templates) {} } }
namespace {

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use src\Business\AuthEntryService;
use src\Controller\AuthEntryController;

const APP_BASE = '';
const MFA_TOTP_DIGITS = 6;
const MFA_TOTP_PERIOD = 30;
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
require_once __DIR__ . '/../app/Service/SessionService.php';
require_once __DIR__ . '/../app/Service/AuthSessionKeys.php';
require_once __DIR__ . '/../app/Service/AuthService.php';
require_once __DIR__ . '/../src/Controller/Controller.php';
require_once __DIR__ . '/../src/Controller/AuthEntryController.php';
require_once __DIR__ . '/../src/Business/AuthEntryService.php';

final class AuthEntryTestSession extends \app\Service\SessionService
{
    public function __construct() {}
    public array $values = ['_IPaddress' => '127.0.0.1'];
    public array $flashes = [];

    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void {}
    public function flash(string $bag, string $type, string $message): void { $this->flashes[$bag][$type][] = $message; }
    public function consumeFlash(string $bag): array { return $this->flashes[$bag] ?? []; }
}

final class AuthEntryTestTranslation { public function setFile(string $file): void {} }
final class AuthEntryTestCsrf { public function rotateToken(): void {} }

final class AuthEntryTestApplication extends Application
{
    public AuthEntryTestSession $session;
    public AuthService $auth;

    public function __construct()
    {
        $this->session = new AuthEntryTestSession();
        $this->auth = new AuthService($this);
    }

    public function get(string $name): ?object
    {
        return match ($name) {
            'sessionService' => $this->session,
            'authService' => $this->auth,
            'translationService' => new AuthEntryTestTranslation(),
            'twig' => new \Twig\Environment(new \Twig\Loader\ArrayLoader(['login.twig' => 'login', 'register.twig' => 'register'])),
            'csrfService' => new AuthEntryTestCsrf(),
            default => null,
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

[$response, $flashes, $calls] = runController('login', ['submit_login' => '1', 'email' => 'mfa@example.test'], ['status' => AuthEntryService::STATUS_APP_MFA_REQUIRED]);
assertContainsValue('login.mfa-app-instructions digits=6 period=30', $flashes['login']['success'] ?? [], 'App MFA should map to app authenticator instructions.');

[$response, $flashes, $calls] = runController('login', ['submit_totp' => '1', 'totp' => ['1','2','3','4','5','6']], ['status' => AuthEntryService::STATUS_AUTHENTICATED, 'jwtToken' => 'jwt']);
assertSameValue(303, $response->getStatusCode(), 'Verify success should redirect to account.');
assertSameValue('Location: /account', $response->getHeaders()[0] ?? null, 'Verify success should target account.');
assertSameValue([['verify', 'login', '123456']], $calls, 'Verify should concatenate OTP digits and delegate JWT issuing to the service.');
assertContainsValue('success-authenticated', $flashes['account']['success'] ?? [], 'Verify success should flash account success.');

fwrite(STDOUT, "AuthEntryController tests passed.\n");

}
