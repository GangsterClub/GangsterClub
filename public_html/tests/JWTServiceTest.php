<?php

declare(strict_types=1);

use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\JWT;
use app\Service\JWTService;
use app\Service\SessionService;
use Firebase\JWT\JWT as FirebaseJWT;
use src\Business\UserService;
use src\Entity\User;

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

final class JWTServiceTestUserService extends UserService
{
    public function __construct(private readonly ?User $user = null)
    {
    }

    public function getUserById(int $userId): ?User
    {
        return $this->user !== null && $userId === $this->user->getId() ? $this->user : null;
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

function makeJWTServiceTestContext(?User $authenticatedUser = null): array
{
    $session = new JWTServiceTestSession();
    $userService = new JWTServiceTestUserService($authenticatedUser);
    $authService = new AuthService($session, new CsrfService($session), $userService);
    $jwt = new JWT();

    return [$session, $authService, $jwt, new JWTService($jwt, $authService)];
}

function makeJWTServiceTestUser(int $id, string $email): User
{
    return new User($id, 'test-user', $email, '127.0.0.1', new DateTime(), new DateTime(), new DateTime());
}

function makeExpiredJWTServiceTestToken(string $identity): string
{
    $now = time();

    return FirebaseJWT::encode([
        'iat' => $now - 120,
        'iss' => APP_DOMAIN,
        'nbf' => $now - 120,
        'exp' => $now - 60,
        'userName' => $identity,
    ], JWT_SECRET, 'HS512');
}

// authorizeRequest_uses_session_jwt_when_authorization_header_is_missing
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
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

// authorizeRequest_rejects_explicit_token_when_identity_does_not_match_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$aliceToken = $jwt->issue('Alice');
$authService->storeJwtToken($aliceToken);
$bobToken = $jwt->issue('Bob');
$result = $service->authorizeRequest(new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $bobToken]));
if (($result instanceof Response) === false) {
    throw new RuntimeException('A token for a different identity should be rejected.');
}
assertSameValue(401, $result->getStatusCode(), 'An identity mismatch should follow the unauthorized response convention.');
assertSameValue('401 Unauthorized: Token identity does not match authenticated session', $result->getContent(), 'An identity mismatch should explain the rejection.');
assertSameValue(['WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="Token identity does not match authenticated session"'], $result->getHeaders(), 'An identity mismatch should include the Bearer challenge.');
assertSameValue(1, $authService->getAuthenticatedUserId(), 'The authenticated session should remain Alice.');
assertSameValue($aliceToken, $authService->getStoredJwtToken(), 'A rejected token must not replace the stored session JWT.');

// authorizeRequest_rejects_stored_token_when_identity_does_not_match_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$bobToken = $jwt->issue('Bob');
$authService->storeJwtToken($bobToken);
$result = $service->authorizeRequest(new JWTServiceTestRequest());
if (($result instanceof Response) === false) {
    throw new RuntimeException('A stored token for a different identity should be rejected.');
}
assertSameValue(401, $result->getStatusCode(), 'A stored-token identity mismatch should return an unauthorized response.');
assertSameValue($bobToken, $authService->getStoredJwtToken(), 'Rejecting a mismatched stored token must not alter it.');

// authorizeRequest_rejects_explicit_token_when_authenticated_user_cannot_be_resolved
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext();
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$storedToken = $jwt->issue('Alice');
$authService->storeJwtToken($storedToken);
$matchingToken = $jwt->issue('Alice');
$result = $service->authorizeRequest(new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $matchingToken]));
if (($result instanceof Response) === false) {
    throw new RuntimeException('An explicit token should be rejected when the authenticated user cannot be resolved.');
}
assertSameValue(401, $result->getStatusCode(), 'An unresolved authenticated user should fail closed with an unauthorized response.');
assertSameValue('401 Unauthorized: Token identity does not match authenticated session', $result->getContent(), 'An unresolved authenticated user should use the identity-mismatch response.');
assertSameValue(1, $authService->getAuthenticatedUserId(), 'Rejecting an unresolved authenticated user must not alter the session user ID.');
assertSameValue($storedToken, $authService->getStoredJwtToken(), 'An unresolved authenticated user must leave the stored JWT unchanged.');

// authorizeRequest_replaces_expired_stored_token_when_identity_matches_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$expiredAliceToken = makeExpiredJWTServiceTestToken('Alice');
$authService->storeJwtToken($expiredAliceToken);
$result = assertAuthorizationSucceeded($service->authorizeRequest(new JWTServiceTestRequest()), 'An expired stored token matching the authenticated session should be replaced.');
assertSameValue('Alice', $result['payload']->userName ?? null, 'The expired matching token replacement should retain Alice\'s identity.');
if ($result['token'] === $expiredAliceToken) {
    throw new RuntimeException('An expired matching token should be replaced with a fresh token.');
}
assertSameValue($result['token'], $authService->getStoredJwtToken(), 'The matching expired token replacement should be stored.');

// authorizeRequest_rejects_expired_explicit_token_for_different_identity
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$aliceToken = $jwt->issue('Alice');
$authService->storeJwtToken($aliceToken);
$expiredBobToken = makeExpiredJWTServiceTestToken('Bob');
$result = $service->authorizeRequest(new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $expiredBobToken]));
if (($result instanceof Response) === false) {
    throw new RuntimeException('An expired token for a different identity should be rejected before its replacement is accepted.');
}
assertSameValue(401, $result->getStatusCode(), 'An expired token identity mismatch should return an unauthorized response.');
assertSameValue('401 Unauthorized: Token identity does not match authenticated session', $result->getContent(), 'The original expired token identity should determine the rejection.');
assertSameValue(1, $authService->getAuthenticatedUserId(), 'Rejecting an expired mismatched token must not alter the session user ID.');
assertSameValue($aliceToken, $authService->getStoredJwtToken(), 'An expired mismatched token must not replace the stored session JWT.');

// authorizeRequest_accepts_explicit_token_when_identity_matches_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$storedToken = $jwt->issue('Alice', ['source' => 'stored']);
$authService->storeJwtToken($storedToken);
$matchingToken = $jwt->issue('Alice', ['source' => 'explicit']);
$result = assertAuthorizationSucceeded($service->authorizeRequest(new JWTServiceTestRequest(['Authorization' => 'Bearer ' . $matchingToken])), 'A token matching the authenticated session identity should authorize successfully.');
assertSameValue('Alice', $result['payload']->userName ?? null, 'The matching explicit token payload should be returned.');
assertSameValue($matchingToken, $authService->getStoredJwtToken(), 'A matching explicit token should replace the stored JWT as before.');

fwrite(STDOUT, "JWTService tests passed.\n");
