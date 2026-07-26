<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\JWT;
use app\Service\JWTService;
use app\Service\SessionService;

if (defined('REQUEST_METHOD') === false) {
    define('REQUEST_METHOD', 'GET');
}

if (defined('JWT_SECRET') === false) {
    define('JWT_SECRET', 'jwt-service-characterization-test-secret-0123456789-abcdefghijklmnopqrstuvwxyz');
}

if (defined('APP_DOMAIN') === false) {
    define('APP_DOMAIN', 'gangsterclub.test');
}

require_once __DIR__ . '/../vendor/autoload.php';

final class JWTServiceTestSession extends SessionService
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

    public function regenerate(): void
    {
    }
}

final class JWTServiceTestApplication extends Application
{
    public function __construct(
        private readonly JWTServiceTestSession $session,
    ) {
    }

    public function get(string $name): ?object
    {
        return $name === 'sessionService' ? $this->session : null;
    }
}

final class JWTServiceTestRequest extends Request
{
    public function __construct(
        private readonly array $testHeaders = [],
        private readonly array $testServer = [],
    ) {
    }

    public function getHeader(string $name): ?string
    {
        $value = $this->testHeaders[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getServer(): array
    {
        return $this->testServer;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertAuthorizationSucceeded(Response|array $result, string $message): array
{
    if (is_array($result) === false) {
        throw new RuntimeException($message . ' Got response status ' . $result->getStatusCode() . '.');
    }

    return $result;
}

function makeJWTServiceTestContext(): array
{
    $session = new JWTServiceTestSession();
    $application = new JWTServiceTestApplication($session);
    $authService = new AuthService($application);
    $jwt = new JWT();

    return [$session, $authService, $jwt, new JWTService($application, $jwt, $authService)];
}

// authorizeRequest_uses_session_jwt_when_authorization_header_is_missing
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext();
$storedToken = $jwt->issue('Alice');
$authService->storeJwtToken($storedToken);
$result = assertAuthorizationSucceeded($service->authorizeRequest(new JWTServiceTestRequest()), 'A stored JWT should authorize a request without an Authorization header.');
assertSameValue($storedToken, $result['token'], 'Authorization without a header should use the stored JWT.');

// authorizeRequest_returns_original_session_token_and_expected_username_payload
assertSameValue($storedToken, $result['token'], 'A valid stored JWT should be returned unchanged.');
assertSameValue('Alice', $result['payload']->userName ?? null, 'A valid stored JWT should expose its userName payload.');

// authorizeRequest_explicit_authorization_header_takes_precedence_over_session_jwt
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext();
$storedToken = $jwt->issue('Alice');
$suppliedToken = $jwt->issue('Bob');
$authService->storeJwtToken($storedToken);
$result = assertAuthorizationSucceeded($service->authorizeRequest(new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $suppliedToken])), 'An explicit Authorization header should authorize successfully.');
assertSameValue($suppliedToken, $result['token'], 'The explicit Authorization header should take precedence over the stored JWT.');
assertSameValue('Bob', $result['payload']->userName ?? null, 'The payload should come from the explicit Authorization header token.');

// authorizeRequest_successful_explicit_header_stores_supplied_token_in_session
assertSameValue($suppliedToken, $authService->getStoredJwtToken(), 'Successful explicit-header authorization should store the supplied JWT.');

// authorizeRequest_without_header_or_session_token_returns_400_token_not_found_and_no_headers
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext();
$result = $service->authorizeRequest(new JWTServiceTestRequest());
if (($result instanceof Response) === false) {
    throw new RuntimeException('Authorization without a header or stored JWT should return a response.');
}
assertSameValue(400, $result->getStatusCode(), 'A missing JWT should return status 400.');
assertSameValue('Token not found in request', $result->getContent(), 'A missing JWT should return the current error body.');
assertSameValue([], $result->getHeaders(), 'A missing JWT response should have no additional headers.');

// authorizeRequest_identity_mismatch_currently_authorizes_bob_token_while_session_remains_alice_known_security_defect
// SECURITY CHARACTERIZATION: this is a known identity-mismatch defect, not desired behavior.
// This expectation is intended to change in the follow-up security fix.
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext();
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$aliceToken = $jwt->issue('Alice');
$authService->storeJwtToken($aliceToken);
$bobToken = $jwt->issue('Bob');
$result = assertAuthorizationSucceeded($service->authorizeRequest(new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $bobToken])), 'The current identity-mismatch defect accepts Bob\'s valid token in Alice\'s authenticated session.');
assertSameValue($bobToken, $result['token'], 'The current defect should authorize Bob\'s supplied token.');
assertSameValue('Bob', $result['payload']->userName ?? null, 'The authorized payload should remain Bob.');
assertSameValue(1, $authService->getAuthenticatedUserId(), 'The authenticated session should remain Alice.');
assertSameValue($bobToken, $authService->getStoredJwtToken(), 'Bob\'s token should become the stored session JWT.');

fwrite(STDOUT, "JWTService tests passed.\n");
