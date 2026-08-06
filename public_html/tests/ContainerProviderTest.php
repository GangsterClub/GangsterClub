<?php

declare(strict_types=1);

use app\Container\Container;
use app\Container\Provider\ApplicationServiceProvider;
use app\Container\Provider\DomainServiceProvider;
use app\Http\Router;
use app\Security\SecuritySecretDecoder;
use app\Service\AuthService;
use app\Service\JWT;
use app\Service\JWTService;
use app\Service\SessionService;
use app\Service\TranslationService;
use src\Business\AuthEntryService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RecoveryCodeService;
use src\Business\TOTPService;
use src\Business\UserService;

spl_autoload_register(static function (string $class): void {
    $prefixes = ['app\\' => __DIR__ . '/../app/', 'src\\' => __DIR__ . '/../src/'];
    foreach ($prefixes as $prefix => $baseDirectory) {
        if (str_starts_with($class, $prefix) === true) {
            $file = $baseDirectory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file) === true) {
                require $file;
            }
        }
    }
});

function assertContainerTrue(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function assertContainerSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertContainerThrows(callable $callback, string $expectedMessage, string $message): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        assertContainerSame($expectedMessage, $throwable->getMessage(), $message);
        return;
    }

    throw new RuntimeException($message . ' No exception was thrown.');
}

final class ContainerProviderConstructable
{
    public function __construct(public readonly Container $container)
    {
    }
}

final class ContainerProviderInvokable
{
    public function __invoke(): stdClass
    {
        return new stdClass();
    }
}

function containerProviderInstanceWithoutConstructor(string $className): object
{
    return (new ReflectionClass($className))->newInstanceWithoutConstructor();
}

$lazyCalls = 0;
$container = new Container();
$container->addService('lazy', static function () use (&$lazyCalls): stdClass {
    $lazyCalls++;
    return new stdClass();
});

assertContainerSame(0, $lazyCalls, 'Factory services should remain lazy until first resolution.');
$first = $container->get('lazy');
$second = $container->get('lazy');
assertContainerSame(1, $lazyCalls, 'Resolved factory services should be memoized.');
assertContainerTrue($first === $second, 'Memoized factory services should return the same object.');
assertContainerSame(null, $container->get('missing'), 'Unknown services should continue to resolve to null.');
assertContainerThrows(
    static fn(): object => $container->getRegisteredService('missing', stdClass::class),
    'missing service is not available.',
    'Missing typed services should report a clear error.'
);
$container->addService('invalidFactory', static fn(): string => 'not-an-object');
assertContainerThrows(
    static fn(): ?object => $container->get('invalidFactory'),
    'invalidFactory service did not resolve to an object.',
    'Factories returning non-objects should report a clear error.'
);

$constructed = $container->make(ContainerProviderConstructable::class);
assertContainerTrue(
    ($constructed instanceof ContainerProviderConstructable) === true
        && $constructed->container === $container,
    'make() should construct controller-style classes with the container.'
);
assertContainerThrows(
    static fn(): object => $container->make('ContainerProviderMissingClass'),
    'Class ContainerProviderMissingClass not found.',
    'make() should report a missing class clearly.'
);
assertContainerThrows(
    static fn(): object => $container->getRegisteredService('lazy', Router::class),
    'lazy service is not available.',
    'Typed lookup should reject a service of the wrong class.'
);

$invokable = new ContainerProviderInvokable();
$container->addService('invokable', $invokable);
assertContainerSame(
    $invokable,
    $container->get('invokable'),
    'Invokable service objects should not be treated as factories.'
);

$applicationServices = new Container();
$applicationServices->register(new ApplicationServiceProvider());
assertContainerTrue($applicationServices->has('router'), 'Application provider should register router.');
assertContainerTrue($applicationServices->has('translationService'), 'Application provider should register translationService.');
assertContainerTrue($applicationServices->get('router') instanceof Router, 'Router service class should be unchanged.');
assertContainerTrue(
    $applicationServices->get('translationService') instanceof TranslationService,
    'Translation service class should be unchanged.'
);

$domainServices = new Container();
$domainServices->register(new DomainServiceProvider());
$expectedNames = [
    'dbh',
    'userRepository',
    'userEmailChangeRepository',
    'emailService',
    'accountService',
    'userService',
    'totpService',
    'userAuthenticatorTotpRepository',
    'emailTotpRepository',
    'authenticationChallengeRepository',
    'authenticationRateLimitRepository',
    'securityAuditEventRepository',
    'recoveryCodeRepository',
    'authenticationChallengeService',
    'authenticationRateLimitService',
    'securityAuditService',
    'recoveryCodeCodec',
    'recoveryCodeService',
    'recoveryFeatureService',
    'authenticatorTotpService',
    'emailTotpService',
    'jwt',
    'jwtService',
    'authEntryService',
];

foreach ($expectedNames as $serviceName) {
    assertContainerTrue($domainServices->has($serviceName), $serviceName . ' should remain registered.');
}

// Session middleware registers these request-scoped dependencies before dispatching controllers.
$domainServices->addService(
    'sessionService',
    containerProviderInstanceWithoutConstructor(SessionService::class)
);
$domainServices->addService(
    'authService',
    containerProviderInstanceWithoutConstructor(AuthService::class)
);
$domainServices->addService(
    'userService',
    containerProviderInstanceWithoutConstructor(UserService::class)
);
$domainServices->addService('jwt', containerProviderInstanceWithoutConstructor(JWT::class));
assertContainerTrue(
    $domainServices->get('jwtService') instanceof JWTService,
    'JWT service should resolve after request-scoped authentication services are registered.'
);

$domainServices->addService(
    'authenticatorTotpService',
    containerProviderInstanceWithoutConstructor(AuthenticatorTOTPService::class)
);
$domainServices->addService(
    'emailTotpService',
    containerProviderInstanceWithoutConstructor(EmailTOTPService::class)
);
$domainServices->addService('totpService', containerProviderInstanceWithoutConstructor(TOTPService::class));
$domainServices->addService('emailService', containerProviderInstanceWithoutConstructor(EmailService::class));
$domainServices->addService(
    'recoveryCodeService',
    containerProviderInstanceWithoutConstructor(RecoveryCodeService::class)
);
assertContainerTrue(
    $domainServices->get('authEntryService') instanceof AuthEntryService,
    'Authentication entry service should resolve after its request-scoped dependencies are registered.'
);

$decoder = new SecuritySecretDecoder();
assertContainerThrows(
    static fn(): string => $decoder->getRequiredSecret('CONTAINER_PROVIDER_TEST_MISSING_SECRET'),
    'CONTAINER_PROVIDER_TEST_MISSING_SECRET must be configured before the security service is used.',
    'Missing security secrets should fail with the current validation guarantee.'
);

define('CONTAINER_PROVIDER_TEST_MALFORMED_SECRET', 'not-a-64-character-hex-secret');
assertContainerThrows(
    static fn(): string => $decoder->getRequiredSecret('CONTAINER_PROVIDER_TEST_MALFORMED_SECRET'),
    'CONTAINER_PROVIDER_TEST_MALFORMED_SECRET must contain exactly 64 hexadecimal characters.',
    'Malformed security secrets should fail with the current validation guarantee.'
);

define('CONTAINER_PROVIDER_TEST_VALID_SECRET', str_repeat('a', 64));
assertContainerSame(
    32,
    strlen($decoder->getRequiredSecret('CONTAINER_PROVIDER_TEST_VALID_SECRET')),
    'Valid security secrets should decode to 32 bytes.'
);
