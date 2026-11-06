<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Middleware\Twig as TwigMiddleware;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\FlashBag;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;
use src\Controller\AuthenticatorSecurity;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/helper.functions.php';

final class AccountSecurityRenderingSession extends SessionService
{
    private array $values = [AuthSessionKeys::AUTHENTICATED_USER_ID => 73];

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

function assertAccountSecurityRendering(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function accountSecurityRenderingStub(string $className): object
{
    return (new ReflectionClass($className))->newInstanceWithoutConstructor();
}

$documentRoot = dirname(__DIR__);
loadEnv($documentRoot . '/.env.example');
defined('DOC_ROOT') || define('DOC_ROOT', $documentRoot);
defined('APP_BASE') || define('APP_BASE', '');
defined('PROTOCOL') || define('PROTOCOL', 'https://');
defined('WEB_ROOT') || define('WEB_ROOT', 'https://example.test/');
defined('REQUEST_URI') || define('REQUEST_URI', '/account/authenticator/disable');
defined('REQUEST_METHOD') || define('REQUEST_METHOD', 'GET');

$applicationClass = new ReflectionClass(Application::class);
$app = $applicationClass->newInstanceWithoutConstructor();
$registerProviders = $applicationClass->getMethod('registerProviders');
$registerProviders->invoke($app);
$app->get(Router::class)->load($documentRoot . '/src/resources/routes.yaml');
$GLOBALS['app'] = $app;

$session = new AccountSecurityRenderingSession();
$csrf = new CsrfService($session);
$app->set(SessionService::class, $session);
$app->set(CsrfService::class, $csrf);
$app->set(FlashBag::class, new FlashBag($session));
$app->set(SecurityFlowSession::class, new SecurityFlowSession($session));
$app->set(AuthService::class, new AuthService($session, $csrf));
$app->set(AuthenticatorTOTPService::class, accountSecurityRenderingStub(AuthenticatorTOTPService::class));
$app->set(RecoveryFeatureService::class, accountSecurityRenderingStub(RecoveryFeatureService::class));

$request = new Request('GET', '/account/authenticator/disable', []);
$response = (new TwigMiddleware($app))->handle(
    $request,
    static function (Request $request) use ($app): Response {
        $controller = $app->get(AuthenticatorSecurity::class);
        assertAccountSecurityRendering(
            $controller instanceof AuthenticatorSecurity,
            'The application provider must resolve the thin security controller.'
        );
        return $controller->disable($request);
    }
);

$html = $response->getContent();
assertAccountSecurityRendering($response->getStatusCode() === 200, 'The disable page must return HTTP 200.');
assertAccountSecurityRendering(
    str_contains($html, '<html lang="en">')
        && str_contains($html, '<title>Verify before disabling authenticator sign-in</title>'),
    'The real base and disable templates must receive Account translations.'
);
assertAccountSecurityRendering(
    str_contains($html, 'name="_csrf_token"')
        && str_contains($html, 'name="authenticator_disable_code"'),
    'The real disable form must retain CSRF and authenticator-code fields.'
);
assertAccountSecurityRendering(
    str_contains($html, 'href="' . Router::path('account') . '"')
        && str_contains($html, 'maxlength="' . AUTHENTICATOR_TOTP_DIGITS . '"'),
    'The disable view model must retain its Account route and authenticator digits.'
);

fwrite(STDOUT, "Account security rendering smoke test passed.\n");
