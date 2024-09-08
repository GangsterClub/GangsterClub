<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

class Twig
{
    /**
     * Summary of application
     * @var Application
     */
    protected Application $application;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    /**
     * Summary of handle
     * @param \app\Http\Request $request
     * @param callable $next
     * @return \app\Http\Response|object
     */
    public function handle(Request $request, callable $next): ?Response
    {
        $loader = new \Twig\Loader\FilesystemLoader(DOC_ROOT . '/src/View/');
        $twig = new \Twig\Environment(
            $loader,
            [
                'cache' => false // DOC_ROOT.'/app/cache/TwigCompilation',
            ]
        );
        $twig->addGlobal('docRoot', WEB_ROOT);
        $twig->addExtension(new \app\Twig\TranslationExtension());
        $this->application->addService('twig', $twig);
        $response = $next($request);
        return $response;
    }
}
