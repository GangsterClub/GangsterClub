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

    public function __construct(string $dir)
    {
        $this->initialize($dir);
        $this->registerServices();
        $routes = $dir.'/app/resources/routes.yaml';
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
        $this->addService('router', $this->router = new Router());
        $this->addService('sessionService', new \app\Business\SessionService());
        $this->addService('translationService', new \app\Business\TranslationService());
    }

    /**
     * Summary of initialize
     * @param string $dir
     * @return void
     */
    private function initialize(string $dir): void
    {
        $https = filter_input(INPUT_SERVER, 'HTTPS', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        define('SRC_CONTROLLER', 'src\\Controller\\');
        define('DOC_ROOT', $this->directory = $dir);
        define('APP_BASE', $this->getBase());
        define('PROTOCOL', 'http'.(isset($https) === true && $https === 'on' ? 's' : '').'://');
        define('WEB_ROOT', PROTOCOL.$this->getHostname().APP_BASE.'/');
        define('REQUEST_URI', filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL));
    }

    /**
     * Summary of getBase
     * @return string
     */
    private function getBase(): string
    {
        return rtrim(
            str_replace(
                '\\', '/', str_replace(
                    str_replace('/', '\\', $this->getDocumentRoot()), '',
                    str_replace('/', '\\', $this->directory)
                )
            ), '/'
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
}
