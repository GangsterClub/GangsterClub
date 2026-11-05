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
use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Business\RecoveryFlowService;
use src\Business\UserService;
use src\Controller\RecoveryFlowController;
use src\Controller\RecoveryStartController;
use src\Entity\User;
use src\Identity\Recovery\Application\RecoveryMode;
use src\Identity\Recovery\Application\RecoveryOutcome;
use src\Identity\Recovery\Application\RecoveryResult;
use src\Identity\Recovery\Http\RecoveryPageRenderer;
use src\Identity\Recovery\Http\RecoveryResponseMapper;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/helper.functions.php';

final class RecoveryRenderingSession extends SessionService
{
    private array $values = ['_IPaddress' => '192.0.2.90'];

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

final class RecoveryRenderingFeature extends RecoveryFeatureService
{
    public function __construct()
    {
    }

    public function getUnusedRecoveryCodeCount(int $userId): int
    {
        return 4;
    }
}

function assertRecoveryRendering(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function recoveryRenderingStub(string $className): object
{
    return (new ReflectionClass($className))->newInstanceWithoutConstructor();
}

$documentRoot = dirname(__DIR__);
loadEnv($documentRoot . '/.env.example');
defined('DOC_ROOT') || define('DOC_ROOT', $documentRoot);
defined('APP_BASE') || define('APP_BASE', '');
defined('PROTOCOL') || define('PROTOCOL', 'http://');
defined('WEB_ROOT') || define('WEB_ROOT', 'http://example.test/');
defined('REQUEST_URI') || define('REQUEST_URI', '/account/recovery-codes');
defined('REQUEST_METHOD') || define('REQUEST_METHOD', 'GET');

// Application's remaining constructor work reads web-server-only INPUT_SERVER values.
// Invoke its exact provider-registration path so this CLI smoke test exercises production composition.
$applicationClass = new ReflectionClass(Application::class);
$app = $applicationClass->newInstanceWithoutConstructor();
$registerProviders = $applicationClass->getMethod('registerProviders');
$registerProviders->invoke($app);
$app->get(Router::class)->load($documentRoot . '/src/resources/routes.yaml');
$GLOBALS['app'] = $app;

$session = new RecoveryRenderingSession();
$pending = new PendingAuthenticationSession($session);
$securityFlow = new SecurityFlowSession($session);
$app->set(SessionService::class, $session);
$app->set(CsrfService::class, new CsrfService($session));
$app->set(FlashBag::class, new FlashBag($session));
$app->set(PendingAuthenticationSession::class, $pending);
$app->set(SecurityFlowSession::class, $securityFlow);
$app->set(AuthService::class, recoveryRenderingStub(AuthService::class));
$app->set(RecoveryFeatureService::class, new RecoveryRenderingFeature());

foreach ([
    AuthenticationChallengeService::class,
    AuthenticatorTOTPService::class,
    EmailService::class,
    EmailTOTPService::class,
    RecoveryCodeService::class,
    RecoveryFlowService::class,
    UserService::class,
] as $serviceId) {
    $app->set($serviceId, recoveryRenderingStub($serviceId));
}

$request = new Request('GET', '/account/recovery-codes', []);
$response = (new TwigMiddleware($app))->handle(
    $request,
    static function (Request $request) use ($app): Response {
        assertRecoveryRendering(
            $app->make(RecoveryStartController::class) instanceof RecoveryStartController,
            'The real application bootstrap must resolve RecoveryStartController.'
        );
        assertRecoveryRendering(
            $app->make(RecoveryFlowController::class) instanceof RecoveryFlowController,
            'The real application bootstrap must resolve RecoveryFlowController.'
        );

        $now = new DateTimeImmutable();
        $user = new User(91, 'recovery-smoke', 'smoke@example.test', '192.0.2.90', $now, $now, $now);
        $challenge = (object) [
            'id' => 501,
            'purpose' => 'authenticator_enrollment',
            'state' => 'email_verification_pending',
        ];
        $result = new RecoveryResult(
            RecoveryOutcome::SHOW_FLOW,
            'email_verification_pending',
            RecoveryMode::ACCOUNT,
            'authenticator_enrollment',
            $user,
            $challenge
        );

        return $app->get(RecoveryPageRenderer::class)->render($result);
    }
);

$assetVersion = max(
    1,
    is_file($documentRoot . '/web/cache/tailwind.css')
        ? (int) filemtime($documentRoot . '/web/cache/tailwind.css') : 1,
    is_file($documentRoot . '/web/css/style.css')
        ? (int) filemtime($documentRoot . '/web/css/style.css') : 1
);
$html = $response->getContent();

assertRecoveryRendering($response->getStatusCode() === 200, 'Recovery rendering must return HTTP 200.');
assertRecoveryRendering(
    in_array('Cache-Control: no-store, private', $response->getHeaders(), true),
    'Recovery rendering must preserve no-store response headers.'
);
assertRecoveryRendering(
    str_contains($html, 'base href="' . WEB_ROOT . '"'),
    'Twig middleware must provide docRoot to the real base template.'
);
assertRecoveryRendering(
    str_contains($html, 'href="cache/tailwind.css?v=' . $assetVersion . '"')
        && str_contains($html, 'href="css/style.css?v=' . $assetVersion . '"'),
    'Twig middleware must provide assetVersion to both stylesheet URLs.'
);
assertRecoveryRendering(
    str_contains($html, 'src="' . WEB_ROOT . 'js/recovery-flow.js"'),
    'The recovery script URL must use the composed document root.'
);
assertRecoveryRendering(
    str_contains($html, '<html lang="en">')
        && str_contains($html, '<title>Account recovery security</title>'),
    'The real base template must receive the translation service.'
);
assertRecoveryRendering(
    str_contains($html, 'href="' . Router::path('setLocale', ['locale' => 'de']) . '"'),
    'The real base template must receive the application router.'
);
assertRecoveryRendering(
    str_contains($html, 'name="_csrf_token"') && str_contains($html, 'smoke@example.test'),
    'Recovery rendering must retain the CSRF extension and typed view data.'
);
assertRecoveryRendering(
    $app->get(\Twig\Environment::class)->getGlobals()['docRoot'] === WEB_ROOT
        && $app->get(\Twig\Environment::class)->getGlobals()['assetVersion'] === $assetVersion,
    'The application-composed Twig environment must expose its required globals.'
);
assertRecoveryRendering(
    $app->get(RecoveryResponseMapper::class) instanceof RecoveryResponseMapper,
    'The feature provider must compose the response mapper with the real Twig renderer.'
);

fwrite(STDOUT, "Recovery rendering smoke test passed.\n");
