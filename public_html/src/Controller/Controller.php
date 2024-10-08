<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;

class Controller
{
    /**
     * Summary of application
     * @var Application
     */
    protected Application $application;

    /**
     * Summary of twig
     * @var \Twig\Environment
     */
    protected \Twig\Environment $twig;

    /**
     * Summary of twigVariables
     * @var array
     */
    protected array $twigVariables = [];

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
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

    /**
     * Summary of __invoke
     * @param \app\Http\Request $request
     * @return string|null
     */
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

    /**
     * Summary of redirectPrevRoute
     * @param \app\Http\Request $request
     * @return void
     */
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
