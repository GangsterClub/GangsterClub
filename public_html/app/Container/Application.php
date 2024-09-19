<?PHP

declare(strict_types=1);

namespace app\Container;

use app\Http\Router;

class Application extends Container
{
    /**
     * Summary of router
     * @var Router
     */
    private Router $router;

    /**
     * Summary of directory
     * @var mixed
     */
    private ?string $directory;

    /**
     * Summary of __construct
     * @param string $dir
     */
    public function __construct(string $dir)
    {
        $this->configure($dir);
        $this->registerServices();
        $routes = $dir . '/app/resources/routes.yaml';
        if (file_exists($routes) === true && (bool) ($router = $this->router) === true) {
            $router->load($routes);
        }
    }

    /**
     * Summary of registerServices
     * @return void
     */
    private function registerServices(): void
    {
        $this->addService('dbh', new \src\Data\Connection());
        $this->addService('router', $this->router = new Router());
        $this->addService('translationService', new \app\Business\TranslationService());
    }

    /**
     * Summary of initialize
     * @param string $dir
     * @return void
     */
    private function configure(string $dir): void
    {
        loadEnv($dir . '/.env');
        $https = filter_input(INPUT_SERVER, 'HTTPS', 515);
        define('DOC_ROOT', $this->directory = $dir);
        define('APP_BASE', $this->getBase());
        define('PROTOCOL', 'http' . (isset($https) === true && $https === 'on' ? 's' : '') . '://');
        define('WEB_ROOT', PROTOCOL . $this->getHostname() . APP_BASE . '/');
        define('REQUEST_URI', (string) filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL));
        define('REQUEST_METHOD', (string) filter_input(INPUT_SERVER, 'REQUEST_METHOD', 515));
    }

    /**
     * Summary of getBase
     * @return string
     */
    private function getBase(): string
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

    /**
     * Summary of getHostname
     * @return string
     */
    private function getHostname(): string
    {
        return filter_input(INPUT_SERVER, 'SERVER_NAME', FILTER_SANITIZE_URL);
    }

    /**
     * Summary of getDocumentRoot
     * @return string
     */
    private function getDocumentRoot(): string
    {
        return str_replace(filter_input(INPUT_SERVER, 'SCRIPT_NAME', FILTER_SANITIZE_URL), '', filter_input(INPUT_SERVER, 'SCRIPT_FILENAME', FILTER_SANITIZE_URL));
    }

    /**
     * Summary of header
     * @param string $location
     * @param bool $replace
     * @param int $response_code
     * @return never
     */
    public function header(string $location, bool $replace = true, int $response_code = 301): never
    {
        header('Location: ' . APP_BASE . $location, $replace, $response_code);
        $this->exit(0);
    }

    /**
     * Summary of exit
     * @param int|null $code
     * @return never
     */
    public function exit(int|null $code = 0): never
    {
        exit($code);
    }
}
