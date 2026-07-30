<?PHP

declare(strict_types=1);

namespace app\Container;

use app\Http\Router;
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

class Application extends Container
{
    private Router $router;

    private ?string $directory;

    public function __construct(string $dir)
    {
        $this->configure($dir);
        $this->registerServices();
        $routes = $dir . '/src/resources/routes.yaml';
        if (file_exists($routes) === true && (bool) ($router = $this->router) === true) {
            $router->load($routes);
        }
    }

    private function registerServices(): void
    {
        $this->addService('dbh', fn(): Connection => new Connection());
        $this->addService('router', $this->router = new Router());
        $this->addService('translationService', new \app\Service\TranslationService());
        $this->addService('userRepository', fn(): UserRepository => new UserRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('userEmailChangeRepository', fn(): UserEmailChangeRepository => new UserEmailChangeRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('emailService', fn(): EmailService => new EmailService());
        $this->addService('accountService', fn(): AccountService => new AccountService(
            $this->getRegisteredService('userRepository', UserRepository::class),
            $this->getRegisteredService('userEmailChangeRepository', UserEmailChangeRepository::class),
            $this->getRegisteredService('emailService', EmailService::class)
        ));
        $this->addService('userService', fn(): UserService => new UserService(
            $this->getRegisteredService('userRepository', UserRepository::class)
        ));
        $this->addService('totpService', fn(): TOTPService => new TOTPService());
        $this->addService('userAuthenticatorTotpRepository', fn(): UserAuthenticatorTOTPRepository => new UserAuthenticatorTOTPRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('emailTotpRepository', fn(): EmailTOTPRepository => new EmailTOTPRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('authenticationChallengeRepository', fn(): AuthenticationChallengeRepository => new AuthenticationChallengeRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('authenticationRateLimitRepository', fn(): AuthenticationRateLimitRepository => new AuthenticationRateLimitRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('securityAuditEventRepository', fn(): SecurityAuditEventRepository => new SecurityAuditEventRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('recoveryCodeRepository', fn(): RecoveryCodeRepository => new RecoveryCodeRepository(
            $this->getRegisteredService('dbh', Connection::class)
        ));
        $this->addService('authenticationChallengeService', fn(): AuthenticationChallengeService => new AuthenticationChallengeService(
            $this->getRegisteredService('dbh', Connection::class),
            $this->getRegisteredService('authenticationChallengeRepository', AuthenticationChallengeRepository::class),
            $this->getRequiredSecret('AUTH_CHALLENGE_PEPPER')
        ));
        $this->addService('authenticationRateLimitService', fn(): AuthenticationRateLimitService => new AuthenticationRateLimitService(
            $this->getRegisteredService('dbh', Connection::class),
            $this->getRegisteredService('authenticationRateLimitRepository', AuthenticationRateLimitRepository::class),
            $this->getRequiredSecret('AUTH_RATE_LIMIT_PEPPER')
        ));
        $this->addService('securityAuditService', fn(): SecurityAuditService => new SecurityAuditService(
            $this->getRegisteredService('securityAuditEventRepository', SecurityAuditEventRepository::class)
        ));
        $this->addService('recoveryCodeCodec', fn(): RecoveryCodeCodec => new RecoveryCodeCodec(
            $this->getRequiredSecret('RECOVERY_CODE_PEPPER')
        ));
        $this->addService('recoveryCodeService', fn(): RecoveryCodeService => new RecoveryCodeService(
            $this->getRegisteredService('dbh', Connection::class),
            $this->getRegisteredService('recoveryCodeRepository', RecoveryCodeRepository::class),
            $this->getRegisteredService('recoveryCodeCodec', RecoveryCodeCodec::class),
            $this->getRegisteredService('authenticationRateLimitService', AuthenticationRateLimitService::class),
            $this->getRegisteredService('securityAuditService', SecurityAuditService::class)
        ));
        $this->addService('recoveryFeatureService', fn(): RecoveryFeatureService => new RecoveryFeatureService(
            $this->getRegisteredService('dbh', Connection::class),
            $this->getRegisteredService('authenticationChallengeService', AuthenticationChallengeService::class),
            $this->getRegisteredService('recoveryCodeService', RecoveryCodeService::class),
            $this->getRegisteredService('recoveryCodeRepository', RecoveryCodeRepository::class),
            $this->getRegisteredService('userAuthenticatorTotpRepository', UserAuthenticatorTOTPRepository::class),
            $this->getRegisteredService('userRepository', UserRepository::class),
            $this->getRegisteredService('securityAuditService', SecurityAuditService::class)
        ));
        $this->addService('authenticatorTotpService', fn(): AuthenticatorTOTPService => new AuthenticatorTOTPService(
            $this->getRegisteredService('totpService', TOTPService::class),
            $this->getRegisteredService('userAuthenticatorTotpRepository', UserAuthenticatorTOTPRepository::class)
        ));
        $this->addService('emailTotpService', fn(): EmailTOTPService => new EmailTOTPService(
            $this->getRegisteredService('totpService', TOTPService::class),
            $this->getRegisteredService('emailTotpRepository', EmailTOTPRepository::class),
            $this->getRegisteredService('sessionService', SessionService::class)
        ));
        $this->addService('jwt', fn(): JWT => new JWT());
        $this->addService('jwtService', fn(): JWTService => new JWTService(
            $this->getRegisteredService('jwt', JWT::class),
            $this->getRegisteredService('authService', AuthService::class),
            $this->getRegisteredService('userService', UserService::class)
        ));
        $this->addService('authEntryService', fn(): AuthEntryService => new AuthEntryService(
            $this->getRegisteredService('userService', UserService::class),
            $this->getRegisteredService('authenticatorTotpService', AuthenticatorTOTPService::class),
            $this->getRegisteredService('emailTotpService', EmailTOTPService::class),
            $this->getRegisteredService('totpService', TOTPService::class),
            $this->getRegisteredService('emailService', EmailService::class),
            $this->getRegisteredService('sessionService', SessionService::class),
            $this->getRegisteredService('recoveryCodeService', RecoveryCodeService::class)
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function getRegisteredService(string $name, string $className): object
    {
        $service = $this->get($name);
        if (($service instanceof $className) === false) {
            throw new \RuntimeException($name . ' service is not available.');
        }

        return $service;
    }

    private function getRequiredSecret(string $constantName): string
    {
        if (defined($constantName) === false) {
            throw new \RuntimeException(
                $constantName . ' must be configured before the security service is used.'
            );
        }

        $encodedSecret = constant($constantName);

        if (
            !is_string($encodedSecret)
            || preg_match('/^[a-fA-F0-9]{64}$/', $encodedSecret) !== 1
        ) {
            throw new \RuntimeException(
                $constantName . ' must contain exactly 64 hexadecimal characters.'
            );
        }

        $decodedSecret = hex2bin($encodedSecret);

        if ($decodedSecret === false || strlen($decodedSecret) !== 32) {
            throw new \RuntimeException(
                $constantName . ' must decode to exactly 32 bytes.'
            );
        }

        return $decodedSecret;
    }

    private function configure(string $dir): void
    {
        loadEnv($dir . '/.env');
        $https = filter_input(INPUT_SERVER, 'HTTPS', 515);
        define('DOC_ROOT', $this->directory = $dir);
        define('APP_BASE', $this->normalizeBase());
        define('PROTOCOL', 'http' . (isset($https) === true && $https === 'on' ? 's' : '') . '://');
        define('WEB_ROOT', PROTOCOL . $this->getHostname() . APP_BASE . '/');
        define('REQUEST_URI', (string) filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL));
        define('REQUEST_METHOD', (string) filter_input(INPUT_SERVER, 'REQUEST_METHOD', 515));
    }

    private function normalizeBase(): string
    {
        return rtrim(
            str_replace(
                '\\',
                '/',
                str_replace(
                    str_replace('/', '\\', $this->getDocumentRoot()),
                    '',
                    str_replace('/', '\\', $this->directory)
                )
            ),
            '/'
        );
    }

    private function getHostname(): string
    {
        return filter_input(INPUT_SERVER, 'SERVER_NAME', FILTER_SANITIZE_URL);
    }

    private function getDocumentRoot(): string
    {
        return str_replace(filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_SANITIZE_URL), '', filter_input(INPUT_SERVER, 'SCRIPT_FILENAME', FILTER_SANITIZE_URL));
    }
}
