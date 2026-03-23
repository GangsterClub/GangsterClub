<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Service\AuthService;

class Controller
{
    protected Application $application;

    protected \Twig\Environment $twig;

    protected array $twigVariables = [];

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->twig = $this->application->get('twig');
        $this->twigVariables = [
            'translation' => $this->application->get('translationService'),
        ];
    }

    public function __destruct()
    {
        $this->application->get('sessionService')->set('PREV_ROUTE', REQUEST_URI);
    }

    public function __invoke(Request $request): string|null
    {
        if (strpos($cls = $this::class, $rpl = SRC_CONTROLLER) !== false) {
            $view = strtolower(str_replace($rpl, '', $cls));
            if (file_exists(DOC_ROOT . '/src/View/' . $view . '.twig') === true) {
                return (string) $this->twig->render($view . '.twig', $this->twigVariables);
            }
        }

        if (strtolower(ENVIRONMENT) !== "production" && DEVELOPMENT === true) {
            print_r('<pre>');
            var_dump($request);
            print_r('</pre>');
        }
        return null;
    }

    protected function auth(): AuthService
    {
        return $this->application->get('authService');
    }

    protected function redirectPrevRoute(Request $request): void
    {
        $session = $this->application->get('sessionService');
        $prevRoute = ($session->get('PREV_ROUTE') ??
            ($request->server('HTTP_REFERER')) ??
            (APP_BASE . '/'));

        if (headers_sent() === false) {
            header('Location: ' . $prevRoute, true, 301);
            $this->application->exit();
        }
    }
}
