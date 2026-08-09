<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Service\TranslationService;
use app\Http\Request;
use app\Http\Response;

class Twig
{
    protected Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function handle(Request $request, callable $next): Response
    {
        $cache = (strtolower(ENVIRONMENT) === 'production' && DEVELOPMENT === false)
            ? DOC_ROOT.'/app/cache/TwigCompilation'
            : false;
        $assetVersion = $this->getAssetVersion(
            DOC_ROOT . '/web/cache/tailwind.css',
            DOC_ROOT . '/web/css/style.css'
        );

        $loader = new \Twig\Loader\FilesystemLoader(DOC_ROOT . '/src/View/');
        $twig = new \Twig\Environment($loader, ['cache' => $cache]);
        $translation = $this->application->get(TranslationService::class);

        $twig->addGlobal('docRoot', WEB_ROOT);
        $twig->addGlobal('assetVersion', $assetVersion);
        $twig->addGlobal('translation', $translation);
        $twig->addGlobal('router', $this->application->get(\app\Http\Router::class));
        $twig->addExtension(new \app\Twig\TranslationExtension());
        $twig->addExtension(new \app\Twig\CsrfExtension($this->application->get(\app\Service\CsrfService::class)));
        $this->application->set(\Twig\Environment::class, $twig);
        $response = $next($request);
        return $response;
    }

    protected function getAssetVersion(string ...$paths): int
    {
        $latest = 1;

        foreach ($paths as $path) {
            if (file_exists($path) === true) {
                $latest = max($latest, (int) filemtime($path));
            }
        }

        return $latest;
    }
}
