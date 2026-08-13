<?php

declare(strict_types=1);

defined('APP_BASE') || define('APP_BASE', '/game');
defined('WEB_ROOT') || define('WEB_ROOT', APP_BASE . '/');

use app\Container\Application;
use app\Http\Request;
use app\Service\AuthService;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;
use src\Controller\AuthenticatorSecurity;
use src\Controller\Controller;

const AUTHENTICATOR_TOTP_DIGITS = 6;

function __(string $key, array $parameters = []): string
{
    return $key;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'app\\' => __DIR__ . '/../app/',
        'src\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDirectory) {
        if (str_starts_with($class, $prefix) === false) {
            continue;
        }

        $file = $baseDirectory
            . str_replace('\\', '/', substr($class, strlen($prefix)))
            . '.php';

        if (is_file($file) === true) {
            require $file;
        }
    }
});

final class AuthenticatorSecurityTestAuth extends AuthService
{
    public ?int $userId = 42;
    public ?string $pendingSecret = 'pending-secret';
    public int $csrfRotations = 0;

    public function __construct()
    {
    }

    public function getAuthenticatedUserId(): ?int
    {
        return $this->userId;
    }

    public function setPendingAuthenticatorSecret(?string $secret): void
    {
        $this->pendingSecret = $secret;
    }

    public function rotateCsrfToken(): void
    {
        ++$this->csrfRotations;
    }

    public function getSecurityChallengeBinding(): string
    {
        return 'security-binding';
    }
}

final class AuthenticatorSecurityTestAuthenticator extends AuthenticatorTOTPService
{
    public array $calls = [];
    public bool $rateLimited = false;

    public function __construct()
    {
    }

    public function verify(int $userId, \src\Business\AuthenticatorTOTPPurpose $purpose, string $code, \src\Business\AuthenticationRateLimitContext $context): bool
    {
        $this->calls[] = [
            'verify',
            $userId,
            $purpose,
            $code,
        ];

        if ($this->rateLimited === true) {
            throw new \src\Business\RateLimitExceededException('authenticator_totp_verify', 60);
        }

        return true;
    }
}

final class AuthenticatorSecurityTestRecovery extends RecoveryFeatureService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function disableAuthenticatorAndRecoveryCodes(int $userId): bool
    {
        $this->calls[] = [
            'disableAuthenticatorAndRecoveryCodes',
            $userId,
        ];

        return true;
    }
}

final class AuthenticatorSecurityTestTranslation
{
    public function setFile(string $file): void
    {
    }
}

final class AuthenticatorSecurityTestSession extends \app\Service\SessionService
{
    public function __construct()
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $key === '_IPaddress' ? '192.0.2.50' : $default;
    }
}

final class AuthenticatorSecurityTestApplication extends Application
{
    public function __construct(
        private readonly AuthenticatorSecurityTestAuth $auth
    ) {
    }

    public function get(string $name): object
    {
        return match ($name) {
            \app\Service\AuthService::class => $this->auth,
            \app\Service\TranslationService::class => new AuthenticatorSecurityTestTranslation(),
            \app\Service\SessionService::class => new AuthenticatorSecurityTestSession(),
            default => throw new RuntimeException($name . ' is missing.'),
        };
    }
}

final class AuthenticatorSecurityTestRequest extends Request
{
    public function __construct(
        private readonly array $fields,
        private readonly array $headers = []
    ) {
    }

    public function getMethod(): string
    {
        return 'POST';
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

function assertAuthenticatorSecuritySame(
    mixed $expected,
    mixed $actual,
    string $message
): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . ' Expected '
            . var_export($expected, true)
            . ', got '
            . var_export($actual, true)
        );
    }
}

$auth = new AuthenticatorSecurityTestAuth();

$authenticator = new AuthenticatorSecurityTestAuthenticator();
$recovery = new AuthenticatorSecurityTestRecovery();
$application = new AuthenticatorSecurityTestApplication($auth);

$controller = (new ReflectionClass(AuthenticatorSecurity::class))
    ->newInstanceWithoutConstructor();

(new ReflectionProperty(Controller::class, 'application'))
    ->setValue($controller, $application);

(new ReflectionProperty(
    AuthenticatorSecurity::class,
    'authenticatorService'
))->setValue($controller, $authenticator);

(new ReflectionProperty(
    AuthenticatorSecurity::class,
    'recoveryFeatureService'
))->setValue($controller, $recovery);

$request = new AuthenticatorSecurityTestRequest(
    [
        'authenticator_disable_code' => '12',
    ],
    [
        'Accept' => 'application/json',
    ]
);

$response = $controller->disable($request);
$payload = json_decode($response->getContent(), true);

assertAuthenticatorSecuritySame(
    422,
    $response->getStatusCode(),
    'Malformed authenticator code should return 422.'
);

assertAuthenticatorSecuritySame(
    false,
    $payload['success'] ?? null,
    'Malformed authenticator code should report failure.'
);

assertAuthenticatorSecuritySame(
    [],
    $authenticator->calls,
    'Malformed code must not be verified.'
);

assertAuthenticatorSecuritySame(
    [],
    $recovery->calls,
    'Malformed code must not disable anything.'
);

$authenticator->rateLimited = true;
$response = $controller->disable(new AuthenticatorSecurityTestRequest(
    ['authenticator_disable_code' => '123456'],
    ['Accept' => 'application/json']
));
$payload = json_decode($response->getContent(), true);

assertAuthenticatorSecuritySame(
    422,
    $response->getStatusCode(),
    'Rate-limited authenticator disable should use the existing invalid-code response.'
);
assertAuthenticatorSecuritySame(
    false,
    $payload['success'] ?? null,
    'Rate-limited authenticator disable should report failure.'
);
assertAuthenticatorSecuritySame(
    \src\Business\AuthenticatorTOTPPurpose::AUTHENTICATOR_DISABLE,
    $authenticator->calls[0][2] ?? null,
    'Authenticator disable must pass the dedicated authenticator purpose.'
);
assertAuthenticatorSecuritySame(
    [],
    $recovery->calls,
    'Rate-limited verification must not disable the authenticator.'
);

fwrite(STDOUT, "AuthenticatorSecurity controller tests passed.\n");
