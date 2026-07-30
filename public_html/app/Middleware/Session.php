<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use app\Service\CsrfService;
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
        $this->application->addService('sessionService', $session);
        $csrfService = new CsrfService($session);
        $this->application->addService('csrfService', $csrfService);
        $userRepository = $this->application->get('userRepository');
        $this->application->addService(
            'authService',
            new AuthService(
                $session,
                $csrfService,
                $userRepository instanceof \src\Data\Repository\UserRepository ? $userRepository : null
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
