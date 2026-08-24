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

interface ContainerProviderContract
{
}

final class ContainerProviderImplementation implements ContainerProviderContract
{
}

function containerProviderInstanceWithoutConstructor(string $className): object
{
    return (new ReflectionClass($className))->newInstanceWithoutConstructor();
}

$lazyCalls = 0;
$container = new Container();
$container->set(stdClass::class, static function () use (&$lazyCalls): stdClass {
    $lazyCalls++;
    return new stdClass();
});

assertContainerSame(0, $lazyCalls, 'Factory services should remain lazy until first resolution.');
$first = $container->get(stdClass::class);
$second = $container->get(stdClass::class);
assertContainerSame(1, $lazyCalls, 'Resolved factory services should be memoized.');
assertContainerTrue($first === $second, 'Resolved factory services should be memoized.');
assertContainerThrows(
    static fn(): object => $container->get(Router::class),
    Router::class . ' service is not registered.',
    'Missing services should report a clear error.'
);
assertContainerSame(null, $container->getOptional(Router::class), 'Optional lookup should return null for a missing service.');

$container->set(Router::class, static fn(): string => 'not-an-object');
assertContainerThrows(
    static fn(): object => $container->get(Router::class),
    Router::class . ' service must resolve to an instance of ' . Router::class . '.',
    'Factories returning incompatible values should report a clear error.'
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
    static fn(): null => $container->set('arbitrary-name', new stdClass()),
    'arbitrary-name is not a class or interface.',
    'Registration should reject arbitrary string identifiers.'
);

$invokable = new ContainerProviderInvokable();
$container->set(ContainerProviderInvokable::class, $invokable);
assertContainerSame(
    $invokable,
    $container->get(ContainerProviderInvokable::class),
    'Invokable service objects should not be treated as factories.'
);

$implementation = new ContainerProviderImplementation();
$container->set(ContainerProviderContract::class, $implementation);
assertContainerSame(
    $implementation,
    $container->get(ContainerProviderContract::class),
    'Interface service identifiers should accept compatible implementations.'
);

$replacement = new ContainerProviderInvokable();
$container->set(ContainerProviderInvokable::class, $replacement);
assertContainerSame(
    $replacement,
    $container->get(ContainerProviderInvokable::class),
    'Setting an existing service identifier should replace the previous service.'
);

$applicationServices = new Container();
$applicationServices->register(new ApplicationServiceProvider());
assertContainerTrue($applicationServices->has(\app\Http\Router::class), 'Application provider should register router.');
assertContainerTrue($applicationServices->has(\app\Service\TranslationService::class), 'Application provider should register translationService.');
assertContainerTrue($applicationServices->get(\app\Http\Router::class) instanceof Router, 'Router service class should be unchanged.');
assertContainerTrue(
    $applicationServices->get(\app\Service\TranslationService::class) instanceof TranslationService,
    'Translation service class should be unchanged.'
);

$domainServices = new Container();
$domainServices->register(new DomainServiceProvider());
$expectedIds = [
    \src\Data\Connection::class,
    \src\Data\Repository\UserRepository::class,
    \src\Data\Repository\UserEmailChangeRepository::class,
    \src\Business\EmailService::class,
    \src\Business\AccountService::class,
    \src\Business\UserService::class,
    \src\Business\TOTPService::class,
    \src\Data\Repository\UserAuthenticatorTOTPRepository::class,
    \src\Data\Repository\EmailTOTPRepository::class,
    \src\Data\Repository\AuthenticationChallengeRepository::class,
    \src\Data\Repository\AuthenticationRateLimitRepository::class,
    \src\Data\Repository\SecurityAuditEventRepository::class,
    \src\Data\Repository\RecoveryCodeRepository::class,
    \src\Business\AuthenticationChallengeService::class,
    \src\Business\AuthenticationRateLimitService::class,
    \src\Business\SecurityAuditService::class,
    \src\Business\RecoveryCodeCodec::class,
    \src\Business\RecoveryCodeService::class,
    \src\Business\RecoveryFeatureService::class,
    \src\Business\RecoveryFlowService::class,
    \src\Business\AuthenticatorTOTPService::class,
    \src\Business\EmailTOTPService::class,
    \app\Service\JWT::class,
    \app\Service\JWTService::class,
    \src\Business\AuthEntryService::class,
];

foreach ($expectedIds as $serviceId) {
    assertContainerTrue($domainServices->has($serviceId), $serviceId . ' should be registered.');
}

// Session middleware registers these request-scoped dependencies before dispatching controllers.
$domainServices->set(
    \app\Service\SessionService::class,
    containerProviderInstanceWithoutConstructor(SessionService::class)
);
$domainServices->set(
    \app\Service\AuthService::class,
    containerProviderInstanceWithoutConstructor(AuthService::class)
);
$domainServices->set(
    \src\Business\UserService::class,
    containerProviderInstanceWithoutConstructor(UserService::class)
);
$domainServices->set(\app\Service\JWT::class, containerProviderInstanceWithoutConstructor(JWT::class));
assertContainerTrue(
    $domainServices->get(\app\Service\JWTService::class) instanceof JWTService,
    'JWT service should resolve after request-scoped authentication services are registered.'
);

$domainServices->set(
    \src\Business\AuthenticatorTOTPService::class,
    containerProviderInstanceWithoutConstructor(AuthenticatorTOTPService::class)
);
$domainServices->set(
    \src\Business\EmailTOTPService::class,
    containerProviderInstanceWithoutConstructor(EmailTOTPService::class)
);
$domainServices->set(\src\Business\TOTPService::class, containerProviderInstanceWithoutConstructor(TOTPService::class));
$domainServices->set(\src\Business\EmailService::class, containerProviderInstanceWithoutConstructor(EmailService::class));
$domainServices->set(
    \src\Business\RecoveryCodeService::class,
    containerProviderInstanceWithoutConstructor(RecoveryCodeService::class)
);
assertContainerTrue(
    $domainServices->get(\src\Business\AuthEntryService::class) instanceof AuthEntryService,
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
