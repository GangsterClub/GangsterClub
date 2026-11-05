<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Middleware\Twig as TwigMiddleware;
use app\Service\AuthService;
use app\Service\CsrfService;
use app\Service\FlashBag;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use src\Business\AuthEntryService;
use src\Business\RecoveryFeatureService;
use src\Controller\Login;
use src\Controller\Register;
use src\Identity\Authentication\Http\AuthEntryViewModelFactory;
use src\Identity\Authentication\Infrastructure\SessionRegistrationFormState;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/helper.functions.php';

final class AuthenticationRenderingSession extends SessionService
{
    private array $values = ['_IPaddress' => '192.0.2.91'];

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

function assertAuthenticationRendering(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function authenticationRenderingStub(string $className): object
{
    return (new ReflectionClass($className))->newInstanceWithoutConstructor();
}

$documentRoot = dirname(__DIR__);
loadEnv($documentRoot . '/.env.example');
defined('DOC_ROOT') || define('DOC_ROOT', $documentRoot);
defined('APP_BASE') || define('APP_BASE', '');
defined('PROTOCOL') || define('PROTOCOL', 'http://');
defined('WEB_ROOT') || define('WEB_ROOT', 'http://example.test/');
defined('REQUEST_URI') || define('REQUEST_URI', '/login');
defined('REQUEST_METHOD') || define('REQUEST_METHOD', 'GET');

$applicationClass = new ReflectionClass(Application::class);
$app = $applicationClass->newInstanceWithoutConstructor();
$registerProviders = $applicationClass->getMethod('registerProviders');
$registerProviders->invoke($app);
$app->get(Router::class)->load($documentRoot . '/src/resources/routes.yaml');
$GLOBALS['app'] = $app;

$session = new AuthenticationRenderingSession();
$csrf = new CsrfService($session);
$pending = new PendingAuthenticationSession($session);
$securityFlow = new SecurityFlowSession($session);
$auth = new AuthService($session, $csrf, null, $pending, $securityFlow);
$app->set(SessionService::class, $session);
$app->set(CsrfService::class, $csrf);
$app->set(FlashBag::class, new FlashBag($session));
$app->set(PendingAuthenticationSession::class, $pending);
$app->set(SecurityFlowSession::class, $securityFlow);
$app->set(AuthService::class, $auth);
$app->set(AuthEntryService::class, authenticationRenderingStub(AuthEntryService::class));
$app->set(RecoveryFeatureService::class, authenticationRenderingStub(RecoveryFeatureService::class));

$responses = (new TwigMiddleware($app))->handle(
    new Request('GET', '/login', []),
    static function (Request $request) use ($app, $session): Response {
        $login = $app->make(Login::class);
        $register = $app->make(Register::class);
        assertAuthenticationRendering($login instanceof Login, 'The application must resolve Login.');
        assertAuthenticationRendering($register instanceof Register, 'The application must resolve Register.');

        $loginResponse = $login->login($request);
        $app->get(SessionRegistrationFormState::class)->remember('remembered-user', 'remembered@example.test');
        $registerResponse = $register->register(new Request('GET', '/register', []));

        $viewModel = $app->get(AuthEntryViewModelFactory::class)->build(
            $app->get(AuthService::class),
            AuthEntryService::MODE_LOGIN
        );
        $requiredFields = [
            'email',
            'registerUsername',
            'usernamePattern',
            'awaitingOtp',
            'uUID',
            'totp',
            'UID',
            'loginRecoveryAvailable',
        ];
        assertAuthenticationRendering(
            array_diff($requiredFields, array_keys($viewModel)) === [],
            'The authentication view model must retain every required field.'
        );
        assertAuthenticationRendering(
            $session->get('register.form_values') === null,
            'Registration remembered values must be consumed by the real render path.'
        );

        return new Response(
            $loginResponse->getContent() . "\n<!-- register -->\n" . $registerResponse->getContent(),
            max($loginResponse->getStatusCode(), $registerResponse->getStatusCode())
        );
    }
);

$assetVersion = max(
    1,
    is_file($documentRoot . '/web/cache/tailwind.css') === true
        ? (int) filemtime($documentRoot . '/web/cache/tailwind.css') : 1,
    is_file($documentRoot . '/web/css/style.css') === true
        ? (int) filemtime($documentRoot . '/web/css/style.css') : 1
);
$html = $responses->getContent();

assertAuthenticationRendering($responses->getStatusCode() === 200, 'Both authentication pages must return HTTP 200.');
assertAuthenticationRendering(
    substr_count($html, 'base href="' . WEB_ROOT . '"') === 2,
    'Twig middleware must provide docRoot to both base-template renders.'
);
assertAuthenticationRendering(
    substr_count($html, 'href="cache/tailwind.css?v=' . $assetVersion . '"') === 2
        && substr_count($html, 'href="css/style.css?v=' . $assetVersion . '"') === 2,
    'Twig middleware must provide versioned asset URLs to both pages.'
);
assertAuthenticationRendering(
    str_contains($html, '<title>Login - GangsterClub.com</title>')
        && str_contains($html, '<title>Register - GangsterClub.com</title>'),
    'The real templates must receive translated login and registration content.'
);
assertAuthenticationRendering(
    str_contains($html, 'href="' . Router::path('setLocale', ['locale' => 'de']) . '"'),
    'The real base template must receive the router global.'
);
assertAuthenticationRendering(
    substr_count($html, 'name="_csrf_token"') >= 2,
    'Both authentication forms must render through the CSRF Twig extension.'
);
assertAuthenticationRendering(
    str_contains($html, 'value="remembered-user"')
        && str_contains($html, 'value="remembered&#x40;example.test"'),
    'The registration render must consume and display remembered values.'
);
assertAuthenticationRendering(
    $app->get(\Twig\Environment::class)->getGlobals()['docRoot'] === WEB_ROOT
        && $app->get(\Twig\Environment::class)->getGlobals()['assetVersion'] === $assetVersion,
    'The application-composed Twig environment must expose required globals.'
);

echo "Authentication rendering smoke test passed.\n";
