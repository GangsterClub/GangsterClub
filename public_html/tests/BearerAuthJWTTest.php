<?php

declare(strict_types=1);

use app\Container\Application;
use app\Container\Container;
use app\Http\Request;
use app\Http\Response;
use app\Middleware\BearerAuthJWT;

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

function makeMiddlewareContext(): array
{
    [, , $jwt, $jwtService] = makeJWTServiceTestContext(
        makeJWTServiceTestUser(42, 'alice@example.test')
    );

    $application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(Container::class, '__construct'))->invoke($application);
    $application->addService('jwtService', $jwtService);

    return [$application, $jwt];
}

function runMiddleware(Application $application, Request $request, int &$calls, ?callable $atNext = null): Response
{
    return (new BearerAuthJWT($application))->handle(
        $request,
        static function () use (&$calls, $atNext): Response {
            $calls++;
            $atNext?->__invoke();
            return new Response('next', 209);
        }
    );
}

function assertSuccess(Response $response, int $calls, string $message): void
{
    assertSameValue(209, $response->getStatusCode(), $message . ' should return next response.');
    assertSameValue(1, $calls, $message . ' should call next exactly once.');
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
    [$application, $jwt] = makeMiddlewareContext();
    [$headers, $server, $selected] = $headersFor($jwt);
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest($headers, $server), $calls);
    assertSuccess($response, $calls, $message);
}

$missingTokenChallenge = ['WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8"'];
foreach ([[], ['Authorization' => '   ']] as $headers) {
    [$application] = makeMiddlewareContext();
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest($headers), $calls);
    assertFailure($response, $calls, 401, '401 Unauthorized: Bearer token required', $missingTokenChallenge, 'Missing Bearer input');
}

$challenge = ['WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="Invalid access token"'];
foreach ([
    ['Basic credentials', 401, '401 Unauthorized: Bearer token required', $missingTokenChallenge],
    ['Bearer not-a-jwt', 401, '401 Unauthorized: Invalid access token', $challenge],
] as [$header, $status, $body, $responseHeaders]) {
    [$application] = makeMiddlewareContext();
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest(['Authorization' => $header]), $calls);
    assertFailure($response, $calls, $status, $body, $responseHeaders, 'Explicit nonblank header');
}

foreach ([['Authorization' => 'Bearer']] as $headers) {
    [$application] = makeMiddlewareContext();
    $calls = 0;
    $response = runMiddleware($application, new JWTServiceTestRequest($headers), $calls);
    assertFailure($response, $calls, 401, '401 Unauthorized: Bearer token required', $missingTokenChallenge, 'Malformed Bearer input');
}

[$application, $jwt] = makeMiddlewareContext();
$token = $jwt->issue('alice@example.test');
$calls = 0;
$response = runMiddleware($application, new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $token]), $calls);
assertSuccess($response, $calls, 'Successful authorization');

fwrite(STDOUT, "BearerAuthJWT middleware tests passed.\n");
