<?PHP

declare(strict_types=1);

namespace app\Container;

use Twig\Environment;
use app\Http\Router;

class Application extends Container
{
    private Router $router;
    private Environment $twig;
    private ?string $directory;
        
    public function __construct(string $dir)
    {
        $this->initialize($dir);
        $this->registerServices();
        $routes = $dir . '/app/resources/routes.yaml';
        if(file_exists($routes) && $router = $this->router)
            $router->load($routes);
    }

    private function registerServices() : void
    {
        $this->addService('router', $this->router = new Router());
        $this->addService('translationService', new \app\Business\TranslationService());
        $this->addService('sessionService', new \app\Business\SessionService());
    }

    private function initialize(string $dir) : void
    {
        define('SRC_CONTROLLER', 'src\\Controller\\');
        define('DOC_ROOT', $this->directory = $dir);
        define('APP_BASE', $this->getBase());
        define('PROTOCOL', 'http'.(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '').'://');
        define('WEB_ROOT', PROTOCOL.$this->getHostname().APP_BASE.'/');
        define('REQUEST_URI', filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL));
    }

    private function getBase() : string
    {
        return rtrim(str_replace('\\', '/',
            str_replace(
                str_replace('/', '\\', $this->getDocumentRoot()), '',
                str_replace('/', '\\', $this->directory)
            )
        ), '/');
    }

    private function getHostname() : string
    {
        return filter_input(INPUT_SERVER, 'SERVER_NAME');
    }

    private function getDocumentRoot() : string
    {
        return str_replace(filter_input(INPUT_SERVER, 'SCRIPT_NAME'), '', filter_input(INPUT_SERVER, 'SCRIPT_FILENAME'));
    }
}
