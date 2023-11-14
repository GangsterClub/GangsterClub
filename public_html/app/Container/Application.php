<?PHP

declare(strict_types=1);

namespace app\Container;

use Twig\Environment;
use app\Http\Router;

class Application extends Container
{
    private ?string $directory;
    private Router $router;
    private Environment $twig;
        
    public function __construct(string $dir)
    {
        $this->initializeTwig($dir);
        $this->registerServices();
        $routes = $dir . '/app/config/routes.yaml';
        if(file_exists($routes) && $router = $this->router)
            $router->load($routes);
    }

    private function registerServices() : void
    {
        $this->addService('router', $this->router = new Router());
        $this->addService('twig', $this->twig);
    }

    private function initializeTwig(string $dir) : void
    {
        define('SRC_CONTROLLER', 'src\\Controller\\');
        define('DOC_ROOT', $this->directory = $dir);
        define('APP_BASE', $this->getBase());
        define('PROTOCOL', 'http'.(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://');
        define('WEB_ROOT', PROTOCOL . $_SERVER['HTTP_HOST'] . APP_BASE . (!empty(APP_BASE) ? '/' : ''));

        $loader = new \Twig\Loader\FilesystemLoader(DOC_ROOT . '/src/View/');
        $this->twig = new Environment($loader, [
            'cache' => false //DOC_ROOT . '/app/cache/TwigCompilation',
        ]);
        $this->twig->addGlobal('docRoot', WEB_ROOT);
    }

    private function getBase() : string
    {
        return str_replace('\\', '/',
            str_replace(
                str_replace('/', '\\', $_SERVER['DOCUMENT_ROOT']), '',
                str_replace('/', '\\',
                    $this->directory
                )
            )
        );
    }
}
