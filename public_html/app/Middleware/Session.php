<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use app\Service\CsrfService;
use app\Service\FlashBag;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;

class Session
{
    protected Application $application;

    private string $savePath;

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->savePath = $savePath = __DIR__ . '/../cache/sessions';
        if (is_dir($savePath) === false) {
            @mkdir($savePath, 0755, true);
        }
    }

    public function handle(Request $request, callable $next): Response
    {
        $session = new SessionService($request);
        $this->application->set(\app\Service\SessionService::class, $session);
        $this->application->set(FlashBag::class, new FlashBag($session));
        $csrfService = new CsrfService($session);
        $this->application->set(\app\Service\CsrfService::class, $csrfService);
        $pendingAuthentication = new PendingAuthenticationSession($session);
        $securityFlow = new SecurityFlowSession($session);
        $this->application->set(PendingAuthenticationSession::class, $pendingAuthentication);
        $this->application->set(SecurityFlowSession::class, $securityFlow);
        $userRepository = $this->application->get(\src\Data\Repository\UserRepository::class);
        $this->application->set(
            AuthService::class,
            new AuthService(
                $session,
                $csrfService,
                $userRepository,
                $pendingAuthentication,
                $securityFlow
            )
        );
        ini_set('session.save_handler', 'files');
        session_set_save_handler($session, true);
        session_save_path($this->savePath);

        // session_start() alternative
        $session->start(seoUrl(APP_NAME));
        $response = $next($request);
        $session->writeClose();
        return $response;
    }
}
