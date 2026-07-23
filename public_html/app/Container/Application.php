<?PHP

declare(strict_types=1);

namespace app\Container;

use app\Http\Router;
use app\Service\AuthRateLimitService;
use app\Service\JWTService;
use app\Service\SessionService;
use src\Business\AuthEntryService;
use src\Business\EmailService;
use src\Business\MFATOTPService;
use src\Business\TOTPEmailService;
use src\Business\TOTPService;
use src\Business\UserService;

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
        $this->addService('dbh', fn(): \src\Data\Connection => new \src\Data\Connection());
        $this->addService('router', $this->router = new Router());
        $this->addService('translationService', new \app\Service\TranslationService());
        $this->addService('userService', fn(): UserService => new UserService($this));
        $this->addService('mfaTotpService', fn(): MFATOTPService => new MFATOTPService($this));
        $this->addService('totpEmailService', fn(): TOTPEmailService => new TOTPEmailService($this));
        $this->addService('totpService', fn(): TOTPService => new TOTPService());
        $this->addService('emailService', fn(): EmailService => new EmailService());
        $this->addService('jwtService', fn(): JWTService => new JWTService($this));
        $this->addService('authRateLimitService', fn(): AuthRateLimitService => new AuthRateLimitService(
            $this->getRegisteredService('sessionService', SessionService::class)
        ));
        $this->addService('authEntryService', fn(): AuthEntryService => new AuthEntryService(
            $this->getRegisteredService('userService', UserService::class),
            $this->getRegisteredService('mfaTotpService', MFATOTPService::class),
            $this->getRegisteredService('totpEmailService', TOTPEmailService::class),
            $this->getRegisteredService('totpService', TOTPService::class),
            $this->getRegisteredService('emailService', EmailService::class),
            $this->getRegisteredService('jwtService', JWTService::class),
            $this->getRegisteredService('sessionService', SessionService::class)
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
