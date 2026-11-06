<?php

declare(strict_types=1);

use app\Http\Request;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\FlashBag;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use app\Service\TranslationService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticatorTOTPService;
use src\Business\AuthenticatorTOTPPurpose;
use src\Business\RecoveryFeatureService;
use src\Controller\AuthenticatorSecurity;
use src\Identity\AccountSecurity\Application\DisableAuthenticator;
use src\Identity\AccountSecurity\Http\AuthenticatorDisableEndpoint;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

require_once __DIR__ . '/../vendor/autoload.php';

defined('APP_BASE') || define('APP_BASE', '/game');
defined('WEB_ROOT') || define('WEB_ROOT', APP_BASE . '/');
defined('APP_MAX_AGE') || define('APP_MAX_AGE', 7200);
defined('AUTHENTICATOR_TOTP_DIGITS') || define('AUTHENTICATOR_TOTP_DIGITS', 6);

final class AuthenticatorSecurityTestSession extends SessionService
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
}

final class AuthenticatorSecurityTestCsrf extends CsrfService
{
    public int $rotations = 0;

    public function __construct()
    {
    }

    public function rotateToken(): string
    {
        ++$this->rotations;
        return 'rotated';
    }
}

final class AuthenticatorSecurityTestAuthenticator extends AuthenticatorTOTPService
{
    public bool $verified = true;

    public function __construct()
    {
    }

    public function verify(
        int $userId,
        AuthenticatorTOTPPurpose $purpose,
        string $submittedCode,
        AuthenticationRateLimitContext $context
    ): bool {
        return $this->verified;
    }
}

final class AuthenticatorSecurityTestRecovery extends RecoveryFeatureService
{
    public bool $succeeds = true;

    public function __construct()
    {
    }

    public function disableAuthenticatorAndRecoveryCodes(int $userId): bool
    {
        return $this->succeeds;
    }
}

final class AuthenticatorSecurityTestTranslation extends TranslationService
{
    public function __construct()
    {
    }

    public function get(string $key, array $replacements = [], bool $useFallback = true): string
    {
        return $key;
    }
}

final class AuthenticatorSecurityTestRequest extends Request
{
    public function __construct(
        private readonly string $method,
        private readonly array $fields = [],
        private readonly array $headers = []
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }

    public function getHeader(string $name): mixed
    {
        return $this->headers[$name] ?? null;
    }
}

function assertAuthenticatorSecuritySame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertAuthenticatorSecurityHeader(array $headers, string $expected, string $message): void
{
    if (in_array($expected, $headers, true) === false) {
        throw new RuntimeException($message . ' Headers: ' . var_export($headers, true));
    }
}

(new Router())->load(__DIR__ . '/../src/resources/routes.yaml');
$session = new AuthenticatorSecurityTestSession();
$csrf = new AuthenticatorSecurityTestCsrf();
$auth = new AuthService($session, $csrf);
$securityFlow = new SecurityFlowSession($session);
$authenticator = new AuthenticatorSecurityTestAuthenticator();
$recovery = new AuthenticatorSecurityTestRecovery();
$flash = new FlashBag($session);
$translation = new AuthenticatorSecurityTestTranslation();
$twig = new Environment(new ArrayLoader([
    'authenticator-disable.twig' => '{{ account.errors|join(",") }}|{{ cancelDisable.backUrl }}|{{ authenticator.digits }}',
]));
$endpoint = new AuthenticatorDisableEndpoint(
    $auth,
    new DisableAuthenticator(
        $authenticator,
        $recovery,
        $session,
        $securityFlow,
        $auth
    ),
    $flash,
    $session,
    $translation,
    $twig
);
$controller = new AuthenticatorSecurity($endpoint);

$response = $controller->startDisable(new AuthenticatorSecurityTestRequest('POST'));
assertAuthenticatorSecuritySame(303, $response->getStatusCode(), 'Unauthenticated disable starts must redirect with 303.');
assertAuthenticatorSecurityHeader($response->getHeaders(), 'Location: /game/login', 'Unauthenticated disable starts must redirect to login.');

$session->values[AuthSessionKeys::AUTHENTICATED_USER_ID] = 42;
$response = $controller->startDisable(new AuthenticatorSecurityTestRequest('POST'));
assertAuthenticatorSecurityHeader($response->getHeaders(), 'Location: /game/account/authenticator/disable', 'Authenticated disable starts must retain the disable route.');

$response = $controller->disable(new AuthenticatorSecurityTestRequest(
    'POST',
    ['authenticator_disable_code' => '12'],
    ['Accept' => 'application/json']
));
$payload = json_decode($response->getContent(), true);
assertAuthenticatorSecuritySame(422, $response->getStatusCode(), 'Malformed JSON submissions must return 422.');
assertAuthenticatorSecuritySame('account.authenticator-disable-code-required', $payload['message'] ?? null, 'Malformed codes must retain their message.');
assertAuthenticatorSecurityHeader($response->getHeaders(), 'Cache-Control: no-store, private', 'Structured responses must remain private and uncached.');

$authenticator->verified = false;
$response = $controller->disable(new AuthenticatorSecurityTestRequest(
    'POST',
    ['authenticator_disable_code' => '123456'],
    ['X-Requested-With' => 'XMLHttpRequest']
));
$payload = json_decode($response->getContent(), true);
assertAuthenticatorSecuritySame('account.authenticator-disable-invalid-code', $payload['message'] ?? null, 'XHR invalid codes must retain their message.');
assertAuthenticatorSecuritySame('authenticator_verification_pending', $payload['state'] ?? null, 'XHR failures must retain their state.');

$authenticator->verified = true;
$recovery->succeeds = false;
$response = $controller->disable(new AuthenticatorSecurityTestRequest(
    'POST',
    ['authenticator_disable_code' => '123456'],
    ['Accept' => 'application/json']
));
$payload = json_decode($response->getContent(), true);
assertAuthenticatorSecuritySame('account.authenticator-disable-error', $payload['message'] ?? null, 'Persistence errors must retain their message.');

$recovery->succeeds = true;
$response = $controller->disable(new AuthenticatorSecurityTestRequest(
    'POST',
    ['authenticator_disable_code' => '123456'],
    ['Accept' => 'application/json']
));
$payload = json_decode($response->getContent(), true);
assertAuthenticatorSecuritySame(200, $response->getStatusCode(), 'Successful structured disablement must return 200.');
assertAuthenticatorSecuritySame([
    'success' => true,
    'state' => 'authenticator_disabled',
    'nextStep' => 'account_security',
    'message' => 'account.authenticator-disabled',
    'errors' => [],
    'redirect' => '/game/account',
], $payload, 'Successful structured disablement must retain its payload.');

$authenticator->verified = false;
$response = $controller->disable(new AuthenticatorSecurityTestRequest(
    'POST',
    ['authenticator_disable_code' => '123456']
));
assertAuthenticatorSecurityHeader($response->getHeaders(), 'Location: /game/account/authenticator/disable', 'HTML failures must retain PRG to the disable page.');
$response = $controller->disable(new AuthenticatorSecurityTestRequest('GET'));
assertAuthenticatorSecuritySame(
    'account.authenticator-disable-invalid-code|/game/account|6',
    $response->getContent(),
    'The disable page must consume its flash and retain its view model.'
);

$authenticator->verified = true;
$response = $controller->disable(new AuthenticatorSecurityTestRequest(
    'POST',
    ['authenticator_disable_code' => '123456']
));
assertAuthenticatorSecurityHeader($response->getHeaders(), 'Location: /game/account', 'HTML success must return to Account.');
assertAuthenticatorSecuritySame(
    ['errors' => [], 'success' => ['account.authenticator-disabled']],
    $flash->consume('account'),
    'HTML success must retain its Account flash message.'
);

unset($session->values[AuthSessionKeys::AUTHENTICATED_USER_ID]);
$response = $controller->disable(new AuthenticatorSecurityTestRequest('GET'));
assertAuthenticatorSecurityHeader($response->getHeaders(), 'Location: /game/login', 'Unauthenticated disable pages must redirect to login.');

fwrite(STDOUT, "Authenticator security controller tests passed.\n");
