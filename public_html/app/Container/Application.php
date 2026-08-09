<?PHP

declare(strict_types=1);

namespace app\Container;

use app\Container\Provider\ApplicationServiceProvider;
use app\Container\Provider\DomainServiceProvider;
use app\Http\Router;

class Application extends Container
{
    private Router $router;

    private ?string $directory;

    public function __construct(string $dir)
    {
        $this->registerProviders();
        $this->configure($dir);
        $routes = $dir . '/src/resources/routes.yaml';
        if (file_exists($routes) === true) {
            $this->router->load($routes);
        }
    }

    private function registerProviders(): void
    {
        $this->register(new DomainServiceProvider());
        $this->register(new ApplicationServiceProvider());
        $this->router = $this->get(\app\Http\Router::class);
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
