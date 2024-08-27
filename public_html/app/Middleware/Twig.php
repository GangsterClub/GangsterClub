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

    public function handle(Request $request, callable $next) : ?Response
    {
        $loader = new \Twig\Loader\FilesystemLoader(DOC_ROOT.'/src/View/');
        $twig = new \Twig\Environment($loader, [
            'cache' => false //DOC_ROOT.'/app/cache/TwigCompilation',
        ]);
        $twig->addGlobal('docRoot', WEB_ROOT);
        $twig->addExtension(new \app\Twig\TranslationExtension());
        $this->application->addService('twig', $twig);
        $response = $next($request);
        return $response;
    }
}
