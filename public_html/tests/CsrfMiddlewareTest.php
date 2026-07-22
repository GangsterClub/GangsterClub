<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Middleware\Csrf;
use app\Service\AuthService;
use app\Service\CsrfService;
use app\Service\SessionService;

if (defined('DOC_ROOT') === false) {
    define('DOC_ROOT', dirname(__DIR__));
}

if (defined('WEB_ROOT') === false) {
    define('WEB_ROOT', 'https://example.test/');
}

require __DIR__ . '/../app/Container/Container.php';
require __DIR__ . '/../app/Container/Application.php';
require __DIR__ . '/../app/Http/Superglobal.php';
require __DIR__ . '/../app/Http/Request.php';
require __DIR__ . '/../app/Http/Response.php';
require __DIR__ . '/../app/Service/SessionService.php';
require __DIR__ . '/../app/Service/AuthSessionKeys.php';
require __DIR__ . '/../app/Service/AuthService.php';
require __DIR__ . '/../app/Service/CsrfService.php';
require __DIR__ . '/../app/Middleware/Csrf.php';

final class CsrfTestSession extends SessionService
{
    private array $store = [];
    public int $regenerateCount = 0;

    public function __construct()
    {
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->store[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerateCount++;
    }
}

final class CsrfTestRequest extends Request
{
    public function __construct(
        private string $testMethod,
        private array $testHeaders = [],
        private array $testPost = [],
    ) {
    }

    public function getMethod(): string
    {
        return $this->testMethod;
    }

    public function getHeader(string $key): mixed
    {
        return $this->testHeaders[$key] ?? null;
    }

    public function post(string $key, $default = null): mixed
    {
        return $this->testPost[$key] ?? $default;
    }
}


final class CsrfTestTranslationService
{
    private array $messages = [
        'csrf-title' => 'Page expired',
        'csrf-message' => 'This page or form expired. Please refresh the page or return to the form, then try again. Your action was not submitted.',
        'csrf-action' => 'Return and try again',
    ];

    public function get(string $key): string
    {
        return $this->messages[$key] ?? $key;
    }
}

final class CsrfTestApplication extends Application
{
    private array $services = [];

    public function __construct()
    {
    }

    public function addService(string $name, object|callable|null $service): void
    {
        $this->services[$name] = $service;
    }

    public function get(string $name): ?object
    {
        $service = $this->services[$name] ?? null;
        if (is_callable($service) === true) {
            $service = $service();
            $this->services[$name] = $service;
        }

        return is_object($service) === true ? $service : null;
    }
}

function makeCsrfApplication(CsrfTestSession $session): CsrfTestApplication
{
    $application = new CsrfTestApplication();
    $application->addService('sessionService', $session);
    $application->addService('csrfService', new CsrfService($session));
    $application->addService('translationService', new CsrfTestTranslationService());
    return $application;
}

function assertTrue(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function runThroughCsrf(CsrfTestApplication $application, Request $request): Response
{
    $middleware = new Csrf($application);

    return $middleware->handle(
        $request,
        static fn (Request $request): Response => Response::html('passed', 204)
    );
}

$session = new CsrfTestSession();
$application = makeCsrfApplication($session);
$response = runThroughCsrf($application, new CsrfTestRequest('GET'));
assertSameValue(204, $response->getStatusCode(), 'Safe requests should pass without a token.');
assertSameValue('passed', $response->getContent(), 'Safe requests should continue to the next handler.');

$session = new CsrfTestSession();
$application = makeCsrfApplication($session);
$response = runThroughCsrf($application, new CsrfTestRequest('POST'));
assertSameValue(419, $response->getStatusCode(), 'State-changing requests without a token should return 419.');
assertTrue(str_contains($response->getContent(), 'This page or form expired') === true, 'HTML CSRF failures should use the friendly translated message.');

$session = new CsrfTestSession();
$application = makeCsrfApplication($session);
$response = runThroughCsrf($application, new CsrfTestRequest('POST', ['Accept' => 'application/json'], [CsrfService::FIELD_NAME => 'invalid-token']));
assertSameValue(419, $response->getStatusCode(), 'Invalid tokens should return 419.');
$payload = json_decode($response->getContent(), true);
assertSameValue('csrf_token_invalid', $payload['error'] ?? null, 'JSON CSRF failures should include a structured error code.');
assertSameValue('This page or form expired. Please refresh the page or return to the form, then try again. Your action was not submitted.', $payload['message'] ?? null, 'JSON CSRF failures should include a translated message.');

$session = new CsrfTestSession();
$application = makeCsrfApplication($session);
$token = $application->get('csrfService')->getToken();
$response = runThroughCsrf($application, new CsrfTestRequest('POST', [], [CsrfService::FIELD_NAME => $token]));
assertSameValue(204, $response->getStatusCode(), 'Valid form tokens should pass.');

$session = new CsrfTestSession();
$application = makeCsrfApplication($session);
$token = $application->get('csrfService')->getToken();
$response = runThroughCsrf($application, new CsrfTestRequest('DELETE', ['X-CSRF-Token' => $token]));
assertSameValue(204, $response->getStatusCode(), 'Valid X-CSRF-Token headers should pass.');


$session = new CsrfTestSession();
$application = makeCsrfApplication($session);
$csrf = $application->get('csrfService');
$tokenBeforeLogin = $csrf->getToken();
$auth = new AuthService($application);
$auth->loginUser(123);
assertSameValue(1, $session->regenerateCount, 'Successful login should regenerate the session ID.');
assertTrue($tokenBeforeLogin !== $csrf->getToken(), 'Successful login should rotate the CSRF token.');

$tokenBeforeLogout = $csrf->getToken();
$auth->logoutUser();
assertSameValue(2, $session->regenerateCount, 'Logout should regenerate the session ID.');
assertTrue($tokenBeforeLogout !== $csrf->getToken(), 'Logout should rotate the CSRF token.');


$middleware = new Csrf(makeCsrfApplication(new CsrfTestSession()));
$getBackUrl = new ReflectionMethod(Csrf::class, 'getBackUrl');
$sameOriginBackUrl = $getBackUrl->invoke($middleware, new CsrfTestRequest('POST', ['Referer' => 'https://example.test/account']));
assertSameValue('https://example.test/account', $sameOriginBackUrl, 'Same-origin CSRF back URLs should be preserved.');
$externalBackUrl = $getBackUrl->invoke($middleware, new CsrfTestRequest('POST', ['Referer' => 'https://evil.example/phish']));
assertSameValue(WEB_ROOT, $externalBackUrl, 'External CSRF back URLs should fall back to WEB_ROOT.');

print "CSRF middleware tests passed.\n";
