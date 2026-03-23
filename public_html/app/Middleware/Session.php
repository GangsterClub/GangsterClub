<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

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

    public function handle(Request $request, callable $next): ?Response
    {
        $session = new \app\Service\SessionService($request);
        $this->application->addService('sessionService', $session);
        $this->application->addService('authService', new \app\Service\AuthService($this->application));
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
