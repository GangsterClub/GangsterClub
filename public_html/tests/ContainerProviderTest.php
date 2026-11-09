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
use src\Business\AuthEntryOtpService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RecoveryCodeService;
use src\Business\TOTPService;
use src\Business\UserService;
use src\Identity\Recovery\Application\AbandonRecoveryFlow;
use src\Identity\Recovery\Application\RecoveryEmailVerification;
use src\Identity\Recovery\Application\RecoveryCodeLifecycle;
use src\Identity\Recovery\Application\RecoveryFlowCoordinator;
use src\Identity\Recovery\Application\RecoveryFlowState;
use src\Identity\Recovery\Application\RecoveryIdentityContext;
use src\Identity\Recovery\Application\RecoveryRateLimitContextFactory;
use src\Identity\Recovery\Application\RecoveryVerification;
use src\Identity\Recovery\Application\StartAccountRecovery;
use src\Identity\Recovery\Application\StartLostAuthenticatorRecovery;
use src\Controller\RecoveryFlow;
use src\Identity\Recovery\Http\RecoveryPageRenderer;
use src\Identity\Recovery\Http\RecoveryResponseMapper;
use src\Controller\RecoveryStart;
use src\Controller\Login;
use src\Controller\Register;
use src\Identity\Authentication\AuthenticationServiceProvider;
use src\Identity\Authentication\Http\AuthEntryEndpoint;
use src\Identity\Authentication\Http\AuthEntryPageRenderer;
use src\Identity\Authentication\Http\AuthEntryRequestHandler;
use src\Identity\Authentication\Http\AuthEntryResponseMapper;
use src\Identity\Authentication\Http\AuthEntryViewModelFactory;
use src\Identity\Authentication\Infrastructure\SessionRegistrationFormState;
use src\Identity\AccountSecurity\AccountSecurityServiceProvider;
use src\Identity\AccountSecurity\Application\DisableAuthenticator;
use src\Identity\AccountSecurity\Application\UpdateTwoStepPreference;
use src\Identity\AccountSecurity\Http\AccountSecurityFormHandler;
use src\Identity\AccountSecurity\Http\AuthenticatorDisableEndpoint;
use src\Identity\Recovery\RecoveryServiceProvider;
use src\Identity\Profile\Application\ProfileManagement;
use src\Identity\Profile\Http\EmailChangeVerification;
use src\Identity\Profile\Http\ProfileFormHandler;
use src\Identity\Profile\ProfileServiceProvider;

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
require_once __DIR__ . '/../vendor/autoload.php';

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
assertContainerSame(
    $replacement,
    $container->make(ContainerProviderInvokable::class),
    'make() should resolve explicitly registered classes before using legacy container construction.'
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

$accountSecurityServices = new Container();
$accountSecurityServices->register(new AccountSecurityServiceProvider());
foreach ([
    DisableAuthenticator::class,
    UpdateTwoStepPreference::class,
    AccountSecurityFormHandler::class,
    AuthenticatorDisableEndpoint::class,
    \src\Controller\AuthenticatorSecurity::class,
] as $serviceId) {
    assertContainerTrue(
        $accountSecurityServices->has($serviceId),
        $serviceId . ' should be registered by AccountSecurity.'
    );
}

$recoveryServices = new Container();
$recoveryServices->register(new RecoveryServiceProvider());
foreach ([
    RecoveryFlowState::class,
    RecoveryIdentityContext::class,
    RecoveryRateLimitContextFactory::class,
    AbandonRecoveryFlow::class,
    RecoveryEmailVerification::class,
    StartAccountRecovery::class,
    StartLostAuthenticatorRecovery::class,
    RecoveryCodeLifecycle::class,
    RecoveryVerification::class,
    RecoveryFlowCoordinator::class,
    RecoveryPageRenderer::class,
    RecoveryResponseMapper::class,
    RecoveryStart::class,
    RecoveryFlow::class,
] as $serviceId) {
    assertContainerTrue($recoveryServices->has($serviceId), $serviceId . ' should be registered by its feature.');
}
foreach ([
    \app\Service\AuthService::class,
    \app\Service\SessionService::class,
    \app\Service\FlashBag::class,
    \app\Service\PendingAuthenticationSession::class,
    \app\Service\SecurityFlowSession::class,
    \app\Service\TranslationService::class,
    \Twig\Environment::class,
    \src\Business\AuthenticationChallengeService::class,
    \src\Business\AuthenticatorTOTPService::class,
    \src\Business\EmailService::class,
    \src\Business\EmailTOTPService::class,
    \src\Business\RecoveryCodeService::class,
    \src\Business\RecoveryFeatureService::class,
    \src\Business\RecoveryFlowService::class,
    \src\Business\UserService::class,
] as $serviceId) {
    $recoveryServices->set($serviceId, containerProviderInstanceWithoutConstructor($serviceId));
}
assertContainerTrue(
    $recoveryServices->make(RecoveryStart::class) instanceof RecoveryStart
        && $recoveryServices->make(RecoveryFlow::class) instanceof RecoveryFlow,
    'Feature controllers should resolve lazily after request-scoped services are available.'
);

$domainServices = new Container();
$domainServices->register(new DomainServiceProvider());
$expectedIds = [
    \src\Data\Connection::class,
    \src\Data\Repository\UserRepository::class,
    \src\Business\EmailService::class,
    \src\Business\UserService::class,
    \src\Business\TOTPService::class,
    \src\Data\Repository\UserAuthenticatorTOTPRepository::class,
    \src\Data\Repository\EmailTOTPRepository::class,
    \src\Data\Repository\AuthenticationChallengeRepository::class,
    \src\Data\Repository\AuthenticationRateLimitRepository::class,
    \src\Data\Repository\SecurityAuditEventRepository::class,
    \src\Data\Repository\RecoveryCodeSetRepository::class,
    \src\Data\Repository\RecoveryCodeHashRepository::class,
    \src\Data\Repository\UserRecoveryCodeStateRepository::class,
    \src\Data\Repository\UserLockRepository::class,
    \src\Data\RecoveryCodePersistence::class,
    \src\Data\RecoveryAccountPersistence::class,
    \src\Business\AuthenticationChallengeService::class,
    \src\Business\AuthenticationRateLimitService::class,
    \src\Business\AuthenticationRateLimitContextProvider::class,
    \src\Business\SecurityAuditService::class,
    \src\Business\RecoveryCodeCodec::class,
    \src\Business\RecoveryCodeGenerator::class,
    \src\Business\RecoveryCodeRateLimiter::class,
    \src\Business\RecoveryCodeService::class,
    \src\Business\RecoveryFeatureService::class,
    \src\Business\RecoveryFlowService::class,
    \src\Business\AuthenticatorTOTPService::class,
    \src\Business\EmailTOTPService::class,
    \app\Service\JWT::class,
    \app\Service\JWTService::class,
];

foreach ($expectedIds as $serviceId) {
    assertContainerTrue($domainServices->has($serviceId), $serviceId . ' should be registered.');
}

$profileServices = new Container();
$profileServices->register(new ProfileServiceProvider());
foreach ([
    \src\Data\Repository\UserEmailChangeRepository::class,
    ProfileManagement::class,
    ProfileFormHandler::class,
    EmailChangeVerification::class,
] as $serviceId) {
    assertContainerTrue($profileServices->has($serviceId), $serviceId . ' should be registered by Profile.');
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
    \app\Service\PendingAuthenticationSession::class,
    containerProviderInstanceWithoutConstructor(\app\Service\PendingAuthenticationSession::class)
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
    \src\Business\AuthEntryOtpService::class,
    containerProviderInstanceWithoutConstructor(\src\Business\AuthEntryOtpService::class)
);
$domainServices->set(
    \src\Business\RecoveryCodeService::class,
    containerProviderInstanceWithoutConstructor(RecoveryCodeService::class)
);

$authenticationServices = new Container();
$authenticationServices->register(new AuthenticationServiceProvider());
foreach ([
    AuthEntryService::class,
    AuthEntryOtpService::class,
    SessionRegistrationFormState::class,
    AuthEntryRequestHandler::class,
    AuthEntryResponseMapper::class,
    AuthEntryViewModelFactory::class,
    AuthEntryPageRenderer::class,
    AuthEntryEndpoint::class,
    Login::class,
    Register::class,
] as $serviceId) {
    assertContainerTrue($authenticationServices->has($serviceId), $serviceId . ' should be registered by its feature.');
}
foreach ([
    \app\Service\AuthService::class,
    \app\Service\FlashBag::class,
    \app\Service\PendingAuthenticationSession::class,
    \app\Service\SecurityFlowSession::class,
    \app\Service\SessionService::class,
    \app\Service\TranslationService::class,
    \Twig\Environment::class,
    \src\Business\AuthenticationRateLimitContextProvider::class,
    \src\Business\AuthenticationRateLimitService::class,
    \src\Business\AuthenticatorTOTPService::class,
    \src\Business\EmailService::class,
    \src\Business\EmailTOTPService::class,
    \src\Business\RecoveryCodeService::class,
    \src\Business\RecoveryFeatureService::class,
    \src\Business\TOTPService::class,
    \src\Business\UserService::class,
] as $serviceId) {
    $authenticationServices->set($serviceId, containerProviderInstanceWithoutConstructor($serviceId));
}
assertContainerTrue(
    $authenticationServices->make(Login::class) instanceof Login
        && $authenticationServices->make(Register::class) instanceof Register,
    'Authentication controllers should resolve lazily after request-scoped services are available.'
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
