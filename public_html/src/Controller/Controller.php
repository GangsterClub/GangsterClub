<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;

class Controller
{
    public const CONTROLLER_NAMESPACE = 'src\\Controller\\';

    protected Application $application;

    protected \Twig\Environment $twig;

    protected array $twigVariables = [];

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->twig = $this->application->get(\Twig\Environment::class);
    }

    public function __destruct()
{
    if (defined('REQUEST_METHOD') === true && REQUEST_METHOD === 'GET') {
        $session = $this->application->get(\app\Service\SessionService::class);
        $session->set('PREV_ROUTE', REQUEST_URI);
    }
}

    public function __invoke(Request $request): Response
    {
        if (strpos($cls = $this::class, $rpl = self::CONTROLLER_NAMESPACE) !== false) {
            $view = strtolower(str_replace($rpl, '', $cls));
            if (file_exists(DOC_ROOT . '/src/View/' . $view . '.twig') === true) {
                return Response::html((string) $this->twig->render($view . '.twig', $this->twigVariables));
            }
        }

        if ($this->canShowRequestDump($request) === true) {
            /* Sensitive information exposure: */
            return Response::html(
                '<pre>' . htmlspecialchars(var_export($request, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
            );
        }

        return Response::html('');
    }

    private function canShowRequestDump(Request $request): bool
    {
        if (strtolower(ENVIRONMENT) === 'production' || DEVELOPMENT !== true) {
            return false;
        }

        $clientIp = (string) $request->server('REMOTE_ADDR', '');
        $developerIps = (string) $request->env('DEVELOPER_IPS', '');
        $allowedIps = array_filter(
            array_map('trim', explode(',', $developerIps)),
            fn(string $ip): bool => $ip !== ''
        );

        return in_array($clientIp, $allowedIps, true);
    }

    protected function auth(): AuthService
    {
        return $this->application->get(\app\Service\AuthService::class);
    }

    protected function redirectPrevRoute(Request $request): Response
    {
        $session = $this->application->get(\app\Service\SessionService::class);
        $prevRoute = ($session->get('PREV_ROUTE') ??
            ($request->server('HTTP_REFERER')) ??
            Router::path('home'));

        return Response::redirect((string) $prevRoute, 303);
    }

    protected function redirectSelf(): Response
    {
        return Response::redirect((string) (REQUEST_URI ?? Router::path('home')), 303);
    }

    protected function flash(string $bag, string $type, string $message): void
    {
        $this->application->get(\app\Service\SessionService::class)->flash($bag, $type, $message);
    }

    protected function consumeFlash(string $bag): array
    {
        return $this->application->get(\app\Service\SessionService::class)->consumeFlash($bag);
    }
}
