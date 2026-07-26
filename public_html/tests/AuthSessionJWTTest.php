<?php

declare(strict_types=1);

use app\Container\Application;
use app\Container\Container;
use app\Http\Request;
use app\Http\Response;
use app\Middleware\AuthSessionJWT;
use app\Service\AuthSessionKeys;

if (defined('REQUEST_METHOD') === false) {
    define('REQUEST_METHOD', 'GET');
}
if (defined('JWT_SECRET') === false) {
    define('JWT_SECRET', 'auth-session-jwt-test-secret-0123456789-abcdefghijklmnopqrstuvwxyz');
}
if (defined('APP_DOMAIN') === false) {
    define('APP_DOMAIN', 'gangsterclub.test');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Support/JWTServiceTestFixtures.php';

function makeMiddlewareContext(bool $authenticated = true): array
{
    [$session, $auth, $jwt, $jwtService] = makeJWTServiceTestContext(
        makeJWTServiceTestUser(42, 'alice@example.test')
    );
    if ($authenticated === true) {
        $session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 42);
    }

    $application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(Container::class, '__construct'))->invoke($application);
    $application->addService('authService', $auth);
    $application->addService('jwtService', $jwtService);
    return [$application, $auth, $jwt];
}

function runMiddleware(Application $application, Request $request, int &$calls, ?callable $atNext = null): Response
{
    return (new AuthSessionJWT($application))->handle(
        $request,
        static function () use (&$calls, $atNext): Response {
            $calls++;
            $atNext?->__invoke();
            return new Response('next', 209);
        }
    );
}

function assertSuccess(Response $response, int $calls, string $stored, string $expected, string $message): void
{
    assertSameValue(209, $response->getStatusCode(), $message . ' should return next response.');
    assertSameValue(1, $calls, $message . ' should call next exactly once.');
    assertSameValue($expected, $stored, $message . ' should persist the selected token.');
}

function assertFailure(Response $response, int $calls, int $status, string $body, array $headers, string $message): void
{
    assertSameValue($status, $response->getStatusCode(), $message . ' status.');
    assertSameValue($body, $response->getContent(), $message . ' body.');
    assertSameValue($headers, $response->getHeaders(), $message . ' headers.');
    assertSameValue(0, $calls, $message . ' should never call next.');
}

$validSources = [
    'Authorization precedence' => static fn ($jwt) => [
        ['Authorization' => 'Bearer ' . ($selected = $jwt->issue('alice@example.test')), 'authorization' => 'Bearer ' . $jwt->issue('alice@example.test')],
        ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt->issue('alice@example.test')], $selected,
    ],
    'lowercase authorization fallback' => static fn ($jwt) => [
        ['authorization' => 'Bearer ' . ($selected = $jwt->issue('alice@example.test'))],
        ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt->issue('alice@example.test')], $selected,
    ],
    'HTTP_AUTHORIZATION fallback' => static fn ($jwt) => [
        [], ['HTTP_AUTHORIZATION' => 'Bearer ' . ($selected = $jwt->issue('alice@example.test'))], $selected,
    ],
];
foreach ($validSources as $message => $headersFor) {
    [$application, $auth, $jwt] = makeMiddlewareContext();
    [$headers, $server, $selected] = $headersFor($jwt);
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest($headers, $server), $calls);
    assertSuccess($response, $calls, (string) $auth->getStoredJwtToken(), $selected, $message);
}

foreach ([[], ['Authorization' => '   ']] as $headers) {
    [$application, $auth, $jwt] = makeMiddlewareContext();
    $auth->storeJwtToken($stored = $jwt->issue('alice@example.test'));
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest($headers), $calls);
    assertSuccess($response, $calls, (string) $auth->getStoredJwtToken(), $stored, 'Stored-token fallback');
}

$challenge = ['WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="Invalid access token"'];
foreach ([
    ['Basic credentials', 400, 'Token not found in request', []],
    ['Bearer not-a-jwt', 401, '401 Unauthorized: Invalid access token', $challenge],
] as [$header, $status, $body, $responseHeaders]) {
    [$application, $auth, $jwt] = makeMiddlewareContext();
    $auth->storeJwtToken($stored = $jwt->issue('alice@example.test'));
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest(['Authorization' => $header]), $calls);
    assertFailure($response, $calls, $status, $body, $responseHeaders, 'Explicit nonblank header');
    assertSameValue($stored, $auth->getStoredJwtToken(), 'A rejected token must not replace the stored token.');
}

foreach ([[], ['Authorization' => 'Bearer']] as $headers) {
    [$application] = makeMiddlewareContext();
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest($headers), $calls);
    assertFailure($response, $calls, 400, 'Token not found in request', [], 'Missing or malformed Bearer input');
}

[$application, $auth, $jwt] = makeMiddlewareContext();
$token = $jwt->issue('alice@example.test');
$calls = 0;
$response = runMiddleware($application, new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $token]), $calls,
    static fn () => assertSameValue($token, $auth->getStoredJwtToken(), 'Token must be persisted before next.'));
assertSuccess($response, $calls, (string) $auth->getStoredJwtToken(), $token, 'Successful authorization');

[$application] = makeMiddlewareContext(false);
$application->addService('jwtService', null);
$calls = 0;
$response = runMiddleware($application, new JWTServiceTestRequest(['Authorization' => 'Bearer invalid']), $calls);
assertSameValue(209, $response->getStatusCode(), 'Unauthenticated requests should bypass JWT authorization.');
assertSameValue(1, $calls, 'Unauthenticated requests should call next exactly once.');

fwrite(STDOUT, "AuthSessionJWT middleware tests passed.\n");
