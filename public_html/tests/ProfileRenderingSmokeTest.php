<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Middleware\Twig as TwigMiddleware;
use app\Service\CsrfService;
use app\Service\SessionService;
use src\Business\EmailService;
use src\Data\Repository\UserEmailChangeRepository;
use src\Data\Repository\UserRepository;
use src\Identity\Profile\Http\EmailChangeVerification;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/helper.functions.php';

final class ProfileRenderingSession extends SessionService
{
    private array $values = [];

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

final class ProfileRenderingUsers extends UserRepository
{
    public array $updates = [];

    public function __construct()
    {
    }

    public function findByEmail(string $email): object|false
    {
        return false;
    }

    public function updateEmail(int $userId, string $email): bool
    {
        $this->updates[] = [$userId, $email];
        return true;
    }
}

final class ProfileRenderingChanges extends UserEmailChangeRepository
{
    public ?object $record;

    public function __construct(string $token)
    {
        $this->record = (object) [
            'id' => 41,
            'user_id' => 17,
            'new_email' => 'updated@example.test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];
    }

    public function findByToken(string $tokenHash): object|false
    {
        return $this->record !== null && $this->record->token_hash === $tokenHash
            ? $this->record
            : false;
    }

    public function markConfirmed(int $id): bool
    {
        return true;
    }

    public function deleteByUserId(int $userId): bool
    {
        $this->record = null;
        return true;
    }
}

function assertProfileRendering(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

$documentRoot = dirname(__DIR__);
loadEnv($documentRoot . '/.env.example');
defined('DOC_ROOT') || define('DOC_ROOT', $documentRoot);
defined('APP_BASE') || define('APP_BASE', '');
defined('PROTOCOL') || define('PROTOCOL', 'https://');
defined('WEB_ROOT') || define('WEB_ROOT', 'https://example.test/');
defined('REQUEST_URI') || define('REQUEST_URI', '/account/email/verify/render-token');
defined('REQUEST_METHOD') || define('REQUEST_METHOD', 'GET');

$applicationClass = new ReflectionClass(Application::class);
$app = $applicationClass->newInstanceWithoutConstructor();
$registerProviders = $applicationClass->getMethod('registerProviders');
$registerProviders->invoke($app);
$app->get(Router::class)->load($documentRoot . '/src/resources/routes.yaml');
$GLOBALS['app'] = $app;

$session = new ProfileRenderingSession();
$users = new ProfileRenderingUsers();
$changes = new ProfileRenderingChanges('render-token');
$app->set(SessionService::class, $session);
$app->set(CsrfService::class, new CsrfService($session));
$app->set(UserRepository::class, $users);
$app->set(UserEmailChangeRepository::class, $changes);
$app->set(EmailService::class, (new ReflectionClass(EmailService::class))->newInstanceWithoutConstructor());

$request = new Request('GET', '/account/email/verify/render-token', []);
$request->setRouteParameters(['token' => 'render-token']);
$response = (new TwigMiddleware($app))->handle(
    $request,
    static function (Request $request) use ($app): Response {
        return $app->get(EmailChangeVerification::class)->verify($request);
    }
);

$html = $response->getContent();
assertProfileRendering($response->getStatusCode() === 200, 'Profile verification rendering must return HTTP 200.');
assertProfileRendering(
    str_contains($html, '<html lang="en">')
        && str_contains($html, '<title>Email verification</title>'),
    'The real base and verification templates must receive translations through Twig middleware.'
);
assertProfileRendering(
    str_contains($html, 'Email address updated successfully.'),
    'Successful verification must retain its translated success message.'
);
assertProfileRendering(
    str_contains($html, 'href="' . Router::path('login') . '"'),
    'The verification page must retain its login route.'
);
assertProfileRendering(
    $users->updates === [[17, 'updated@example.test']] && $changes->record === null,
    'Rendering a valid verification must complete and consume the email change exactly once.'
);

fwrite(STDOUT, "Profile rendering smoke test passed.\n");
