<?php

declare(strict_types=1);

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
require_once __DIR__ . '/Support/JWTServiceTestFixtures.php';

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

// authorize_accepts_a_raw_stored_jwt
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$storedToken = $jwt->issue('Alice');
$authService->storeJwtToken($storedToken);
$result = assertAuthorizationSucceeded($service->authorize($storedToken), 'A raw stored JWT should authorize.');
assertSameValue($storedToken, $result['token'], 'Authorization should return the raw JWT unchanged.');

// authorize_returns_original_session_token_and_expected_username_payload
assertSameValue($storedToken, $result['token'], 'A valid stored JWT should be returned unchanged.');
assertSameValue('Alice', $result['payload']->userName ?? null, 'A valid stored JWT should expose its userName payload.');

// authorize_does_not_read_or_write_the_stored_session_jwt
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext();
$storedToken = $jwt->issue('Alice');
$suppliedToken = $jwt->issue('Bob');
$authService->storeJwtToken($storedToken);
$result = assertAuthorizationSucceeded($service->authorize($suppliedToken), 'A supplied raw JWT should authorize successfully.');
assertSameValue($suppliedToken, $result['token'], 'The supplied raw JWT should be returned.');
assertSameValue('Bob', $result['payload']->userName ?? null, 'The payload should come from the supplied raw JWT.');
assertSameValue($storedToken, $authService->getStoredJwtToken(), 'JWTService must not persist the supplied JWT.');

// authorize_rejects_explicit_token_when_identity_does_not_match_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$aliceToken = $jwt->issue('Alice');
$authService->storeJwtToken($aliceToken);
$bobToken = $jwt->issue('Bob');
$result = $service->authorize($bobToken);
assertSameValue('unauthorized', $result['status'] ?? null, 'An identity mismatch should be rejected.');
assertSameValue('Token identity does not match authenticated session', $result['description'] ?? null, 'An identity mismatch should explain the rejection.');
assertSameValue(1, $authService->getAuthenticatedUserId(), 'The authenticated session should remain Alice.');
assertSameValue($aliceToken, $authService->getStoredJwtToken(), 'A rejected token must not replace the stored session JWT.');

// authorize_rejects_stored_token_when_identity_does_not_match_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$bobToken = $jwt->issue('Bob');
$authService->storeJwtToken($bobToken);
$result = $service->authorize($bobToken);
assertSameValue('unauthorized', $result['status'] ?? null, 'A stored-token identity mismatch should return an unauthorized result.');
assertSameValue($bobToken, $authService->getStoredJwtToken(), 'Rejecting a mismatched stored token must not alter it.');

// authorize_rejects_explicit_token_when_authenticated_user_cannot_be_resolved
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext();
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$storedToken = $jwt->issue('Alice');
$authService->storeJwtToken($storedToken);
$matchingToken = $jwt->issue('Alice');
$result = $service->authorize($matchingToken);
assertSameValue('unauthorized', $result['status'] ?? null, 'An unresolved authenticated user should fail closed.');
assertSameValue('Token identity does not match authenticated session', $result['description'] ?? null, 'An unresolved authenticated user should use the identity-mismatch result.');
assertSameValue(1, $authService->getAuthenticatedUserId(), 'Rejecting an unresolved authenticated user must not alter the session user ID.');
assertSameValue($storedToken, $authService->getStoredJwtToken(), 'An unresolved authenticated user must leave the stored JWT unchanged.');

// authorize_replaces_expired_stored_token_when_identity_matches_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$expiredAliceToken = makeExpiredJWTServiceTestToken('Alice');
$authService->storeJwtToken($expiredAliceToken);
$result = assertAuthorizationSucceeded($service->authorize($expiredAliceToken), 'An expired stored token matching the authenticated session should be replaced.');
assertSameValue('Alice', $result['payload']->userName ?? null, 'The expired matching token replacement should retain Alice\'s identity.');
if ($result['token'] === $expiredAliceToken) {
    throw new RuntimeException('An expired matching token should be replaced with a fresh token.');
}
assertSameValue($expiredAliceToken, $authService->getStoredJwtToken(), 'JWTService must not persist the matching expired token replacement.');

// authorize_rejects_expired_explicit_token_for_different_identity
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$aliceToken = $jwt->issue('Alice');
$authService->storeJwtToken($aliceToken);
$expiredBobToken = makeExpiredJWTServiceTestToken('Bob');
$result = $service->authorize($expiredBobToken);
assertSameValue('unauthorized', $result['status'] ?? null, 'An expired token identity mismatch should return an unauthorized result.');
assertSameValue('Token identity does not match authenticated session', $result['description'] ?? null, 'The original expired token identity should determine the rejection.');
assertSameValue(1, $authService->getAuthenticatedUserId(), 'Rejecting an expired mismatched token must not alter the session user ID.');
assertSameValue($aliceToken, $authService->getStoredJwtToken(), 'An expired mismatched token must not replace the stored session JWT.');

// authorize_accepts_explicit_token_when_identity_matches_authenticated_session
[$session, $authService, $jwt, $service] = makeJWTServiceTestContext(makeJWTServiceTestUser(1, 'Alice'));
$session->set(AuthSessionKeys::AUTHENTICATED_USER_ID, 1);
$storedToken = $jwt->issue('Alice', ['source' => 'stored']);
$authService->storeJwtToken($storedToken);
$matchingToken = $jwt->issue('Alice', ['source' => 'explicit']);
$result = assertAuthorizationSucceeded($service->authorize($matchingToken), 'A token matching the authenticated session identity should authorize successfully.');
assertSameValue('Alice', $result['payload']->userName ?? null, 'The matching explicit token payload should be returned.');
assertSameValue($storedToken, $authService->getStoredJwtToken(), 'JWTService must leave persistence to its caller.');

fwrite(STDOUT, "JWTService tests passed.\n");
