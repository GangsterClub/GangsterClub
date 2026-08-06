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

        $container->addService('dbh', fn(): Connection => new Connection());
        $container->addService('userRepository', fn(): UserRepository => new UserRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
        $container->addService('userEmailChangeRepository', fn(): UserEmailChangeRepository => new UserEmailChangeRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
        $container->addService('emailService', fn(): EmailService => new EmailService());
        $container->addService('accountService', fn(): AccountService => new AccountService(
            $container->getRegisteredService('userRepository', UserRepository::class),
            $container->getRegisteredService('userEmailChangeRepository', UserEmailChangeRepository::class),
            $container->getRegisteredService('emailService', EmailService::class)
        ));
        $container->addService('userService', fn(): UserService => new UserService(
            $container->getRegisteredService('userRepository', UserRepository::class)
        ));
        $container->addService('totpService', fn(): TOTPService => new TOTPService());
        $this->registerSecurityRepositories($container);
        $this->registerSecurityServices($container, $secrets);
        $this->registerAuthenticationServices($container);
    }

    private function registerSecurityRepositories(Container $container): void
    {
        $container->addService('userAuthenticatorTotpRepository', fn(): UserAuthenticatorTOTPRepository => new UserAuthenticatorTOTPRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
        $container->addService('emailTotpRepository', fn(): EmailTOTPRepository => new EmailTOTPRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
        $container->addService('authenticationChallengeRepository', fn(): AuthenticationChallengeRepository => new AuthenticationChallengeRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
        $container->addService('authenticationRateLimitRepository', fn(): AuthenticationRateLimitRepository => new AuthenticationRateLimitRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
        $container->addService('securityAuditEventRepository', fn(): SecurityAuditEventRepository => new SecurityAuditEventRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
        $container->addService('recoveryCodeRepository', fn(): RecoveryCodeRepository => new RecoveryCodeRepository(
            $container->getRegisteredService('dbh', Connection::class)
        ));
    }

    private function registerSecurityServices(Container $container, SecuritySecretDecoder $secrets): void
    {
        $container->addService('authenticationChallengeService', fn(): AuthenticationChallengeService => new AuthenticationChallengeService(
            $container->getRegisteredService('dbh', Connection::class),
            $container->getRegisteredService('authenticationChallengeRepository', AuthenticationChallengeRepository::class),
            $secrets->getRequiredSecret('AUTH_CHALLENGE_PEPPER')
        ));
        $container->addService('authenticationRateLimitService', fn(): AuthenticationRateLimitService => new AuthenticationRateLimitService(
            $container->getRegisteredService('dbh', Connection::class),
            $container->getRegisteredService('authenticationRateLimitRepository', AuthenticationRateLimitRepository::class),
            $secrets->getRequiredSecret('AUTH_RATE_LIMIT_PEPPER')
        ));
        $container->addService('securityAuditService', fn(): SecurityAuditService => new SecurityAuditService(
            $container->getRegisteredService('securityAuditEventRepository', SecurityAuditEventRepository::class)
        ));
        $container->addService('recoveryCodeCodec', fn(): RecoveryCodeCodec => new RecoveryCodeCodec(
            $secrets->getRequiredSecret('RECOVERY_CODE_PEPPER')
        ));
        $container->addService('recoveryCodeService', fn(): RecoveryCodeService => new RecoveryCodeService(
            $container->getRegisteredService('dbh', Connection::class),
            $container->getRegisteredService('recoveryCodeRepository', RecoveryCodeRepository::class),
            $container->getRegisteredService('recoveryCodeCodec', RecoveryCodeCodec::class),
            $container->getRegisteredService('authenticationRateLimitService', AuthenticationRateLimitService::class),
            $container->getRegisteredService('securityAuditService', SecurityAuditService::class)
        ));
        $container->addService('recoveryFeatureService', fn(): RecoveryFeatureService => new RecoveryFeatureService(
            $container->getRegisteredService('dbh', Connection::class),
            $container->getRegisteredService('authenticationChallengeService', AuthenticationChallengeService::class),
            $container->getRegisteredService('recoveryCodeService', RecoveryCodeService::class),
            $container->getRegisteredService('recoveryCodeRepository', RecoveryCodeRepository::class),
            $container->getRegisteredService('userAuthenticatorTotpRepository', UserAuthenticatorTOTPRepository::class),
            $container->getRegisteredService('userRepository', UserRepository::class),
            $container->getRegisteredService('securityAuditService', SecurityAuditService::class)
        ));
    }

    private function registerAuthenticationServices(Container $container): void
    {
        $container->addService('authenticatorTotpService', fn(): AuthenticatorTOTPService => new AuthenticatorTOTPService(
            $container->getRegisteredService('totpService', TOTPService::class),
            $container->getRegisteredService('userAuthenticatorTotpRepository', UserAuthenticatorTOTPRepository::class)
        ));
        $container->addService('emailTotpService', fn(): EmailTOTPService => new EmailTOTPService(
            $container->getRegisteredService('totpService', TOTPService::class),
            $container->getRegisteredService('emailTotpRepository', EmailTOTPRepository::class),
            $container->getRegisteredService('sessionService', SessionService::class)
        ));
        $container->addService('jwt', fn(): JWT => new JWT());
        $container->addService('jwtService', fn(): JWTService => new JWTService(
            $container->getRegisteredService('jwt', JWT::class),
            $container->getRegisteredService('authService', AuthService::class),
            $container->getRegisteredService('userService', UserService::class)
        ));
        $container->addService('authEntryService', fn(): AuthEntryService => new AuthEntryService(
            $container->getRegisteredService('userService', UserService::class),
            $container->getRegisteredService('authenticatorTotpService', AuthenticatorTOTPService::class),
            $container->getRegisteredService('emailTotpService', EmailTOTPService::class),
            $container->getRegisteredService('totpService', TOTPService::class),
            $container->getRegisteredService('emailService', EmailService::class),
            $container->getRegisteredService('sessionService', SessionService::class),
            $container->getRegisteredService('recoveryCodeService', RecoveryCodeService::class)
        ));
    }
}
