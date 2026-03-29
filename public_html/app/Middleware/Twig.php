<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
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
        $loader = new \Twig\Loader\FilesystemLoader(DOC_ROOT . '/src/View/');
        $assetVersion = $this->getAssetVersion(
            DOC_ROOT . '/web/cache/tailwind.css',
            DOC_ROOT . '/web/css/style.css'
        );
        $twig = new \Twig\Environment(
            $loader,
            [
                'cache' => false // DOC_ROOT.'/app/cache/TwigCompilation',
            ]
        );
        $twig->addGlobal('docRoot', WEB_ROOT);
        $twig->addGlobal('assetVersion', $assetVersion);
        $twig->addExtension(new \app\Twig\TranslationExtension());
        $this->application->addService('twig', $twig);
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
