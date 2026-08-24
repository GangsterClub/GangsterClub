<?PHP

declare(strict_types=1);

namespace app\Container\Provider;

use app\Container\Container;
use app\Container\ServiceProvider;
use app\Security\SecuritySecretDecoder;
use app\Service\AuthService;
use app\Service\JWT;
use app\Service\JWTService;
use app\Service\SessionService;
use src\Business\AccountService;
use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitService;
use src\Business\AuthEntryService;
use src\Business\EmailService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailTOTPService;
use src\Business\RecoveryCodeCodec;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Business\RecoveryFlowService;
use src\Business\SecurityAuditService;
use src\Business\TOTPService;
use src\Business\UserService;
use src\Data\Connection;
use src\Data\Repository\AuthenticationChallengeRepository;
use src\Data\Repository\AuthenticationRateLimitRepository;
use src\Data\Repository\EmailTOTPRepository;
use src\Data\Repository\RecoveryCodeRepository;
use src\Data\Repository\SecurityAuditEventRepository;
use src\Data\Repository\UserEmailChangeRepository;
use src\Data\Repository\UserRepository;
use src\Data\Repository\UserAuthenticatorTOTPRepository;

final class DomainServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $secrets = new SecuritySecretDecoder();

        $container->set(\src\Data\Connection::class, fn(): Connection => new Connection());
        $container->set(\src\Data\Repository\UserRepository::class, fn(): UserRepository => new UserRepository(
            $container->get(\src\Data\Connection::class)
        ));
        $container->set(\src\Data\Repository\UserEmailChangeRepository::class, fn(): UserEmailChangeRepository => new UserEmailChangeRepository(
            $container->get(\src\Data\Connection::class)
        ));
        $container->set(\src\Business\EmailService::class, fn(): EmailService => new EmailService());
        $container->set(\src\Business\AccountService::class, fn(): AccountService => new AccountService(
            $container->get(\src\Data\Repository\UserRepository::class),
            $container->get(\src\Data\Repository\UserEmailChangeRepository::class),
            $container->get(\src\Business\EmailService::class)
        ));
        $container->set(\src\Business\UserService::class, fn(): UserService => new UserService(
            $container->get(\src\Data\Repository\UserRepository::class)
        ));
        $container->set(\src\Business\TOTPService::class, fn(): TOTPService => new TOTPService());
        $this->registerSecurityRepositories($container);
        $this->registerSecurityServices($container, $secrets);
        $this->registerAuthenticationServices($container);
    }

    private function registerSecurityRepositories(Container $container): void
    {
        $container->set(\src\Data\Repository\UserAuthenticatorTOTPRepository::class, fn(): UserAuthenticatorTOTPRepository => new UserAuthenticatorTOTPRepository(
            $container->get(\src\Data\Connection::class)
        ));
        $container->set(\src\Data\Repository\EmailTOTPRepository::class, fn(): EmailTOTPRepository => new EmailTOTPRepository(
            $container->get(\src\Data\Connection::class)
        ));
        $container->set(\src\Data\Repository\AuthenticationChallengeRepository::class, fn(): AuthenticationChallengeRepository => new AuthenticationChallengeRepository(
            $container->get(\src\Data\Connection::class)
        ));
        $container->set(\src\Data\Repository\AuthenticationRateLimitRepository::class, fn(): AuthenticationRateLimitRepository => new AuthenticationRateLimitRepository(
            $container->get(\src\Data\Connection::class)
        ));
        $container->set(\src\Data\Repository\SecurityAuditEventRepository::class, fn(): SecurityAuditEventRepository => new SecurityAuditEventRepository(
            $container->get(\src\Data\Connection::class)
        ));
        $container->set(\src\Data\Repository\RecoveryCodeRepository::class, fn(): RecoveryCodeRepository => new RecoveryCodeRepository(
            $container->get(\src\Data\Connection::class)
        ));
    }

    private function registerSecurityServices(Container $container, SecuritySecretDecoder $secrets): void
    {
        $container->set(\src\Business\AuthenticationChallengeService::class, fn(): AuthenticationChallengeService => new AuthenticationChallengeService(
            $container->get(\src\Data\Connection::class),
            $container->get(\src\Data\Repository\AuthenticationChallengeRepository::class),
            $secrets->getRequiredSecret('AUTH_CHALLENGE_PEPPER')
        ));
        $container->set(\src\Business\AuthenticationRateLimitService::class, fn(): AuthenticationRateLimitService => new AuthenticationRateLimitService(
            $container->get(\src\Data\Connection::class),
            $container->get(\src\Data\Repository\AuthenticationRateLimitRepository::class),
            $secrets->getRequiredSecret('AUTH_RATE_LIMIT_PEPPER')
        ));
        $container->set(\src\Business\SecurityAuditService::class, fn(): SecurityAuditService => new SecurityAuditService(
            $container->get(\src\Data\Repository\SecurityAuditEventRepository::class)
        ));
        $container->set(\src\Business\RecoveryCodeCodec::class, fn(): RecoveryCodeCodec => new RecoveryCodeCodec(
            $secrets->getRequiredSecret('RECOVERY_CODE_PEPPER')
        ));
        $container->set(\src\Business\RecoveryCodeService::class, fn(): RecoveryCodeService => new RecoveryCodeService(
            $container->get(\src\Data\Connection::class),
            $container->get(\src\Data\Repository\RecoveryCodeRepository::class),
            $container->get(\src\Business\RecoveryCodeCodec::class),
            $container->get(\src\Business\AuthenticationRateLimitService::class),
            $container->get(\src\Business\SecurityAuditService::class)
        ));
        $container->set(\src\Business\RecoveryFeatureService::class, fn(): RecoveryFeatureService => new RecoveryFeatureService(
            $container->get(\src\Data\Connection::class),
            $container->get(\src\Business\AuthenticationChallengeService::class),
            $container->get(\src\Business\RecoveryCodeService::class),
            $container->get(\src\Data\Repository\RecoveryCodeRepository::class),
            $container->get(\src\Data\Repository\UserAuthenticatorTOTPRepository::class),
            $container->get(\src\Data\Repository\UserRepository::class),
            $container->get(\src\Business\SecurityAuditService::class)
        ));
    }

    private function registerAuthenticationServices(Container $container): void
    {
        $container->set(\src\Business\AuthenticatorTOTPService::class, fn(): AuthenticatorTOTPService => new AuthenticatorTOTPService(
            $container->get(\src\Business\TOTPService::class),
            $container->get(\src\Data\Repository\UserAuthenticatorTOTPRepository::class),
            $container->get(\src\Business\AuthenticationRateLimitService::class)
        ));
        $container->set(\src\Business\RecoveryFlowService::class, fn(): RecoveryFlowService => new RecoveryFlowService(
            $container->get(\src\Business\AuthenticationChallengeService::class),
            $container->get(\src\Business\AuthenticatorTOTPService::class)
        ));
        $container->set(\src\Business\EmailTOTPService::class, fn(): EmailTOTPService => new EmailTOTPService(
            $container->get(\src\Business\TOTPService::class),
            $container->get(\src\Data\Repository\EmailTOTPRepository::class),
            $container->get(\src\Business\AuthenticationRateLimitService::class)
        ));
        $container->set(\app\Service\JWT::class, fn(): JWT => new JWT());
        $container->set(\app\Service\JWTService::class, fn(): JWTService => new JWTService(
            $container->get(\app\Service\JWT::class),
            $container->get(\app\Service\AuthService::class),
            $container->get(\src\Business\UserService::class)
        ));
        $container->set(\src\Business\AuthEntryService::class, fn(): AuthEntryService => new AuthEntryService(
            $container->get(\src\Business\UserService::class),
            $container->get(\src\Business\AuthenticatorTOTPService::class),
            $container->get(\src\Business\EmailTOTPService::class),
            $container->get(\src\Business\TOTPService::class),
            $container->get(\src\Business\EmailService::class),
            $container->get(\app\Service\SessionService::class),
            $container->get(\src\Business\RecoveryCodeService::class)
        ));
    }
}
