<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

class Session
{
    /**
     * Summary of application
     * @var Application
     */
    protected Application $application;

    /**
     * Summary of savePath
     * @var string
     */
    private string $savePath;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->savePath = $savePath = __DIR__ . '/../cache/sessions';
        if (is_dir($savePath) === false) {
            @mkdir($savePath, 0755, true);
        }
    }

    /**
     * Summary of handle
     * @param \app\Http\Request $request
     * @param callable $next
     * @return \app\Http\Response|object
     */
    public function handle(Request $request, callable $next): ?Response
    {
        $session = new \app\Service\SessionService($request);
        $this->application->addService('sessionService', $session);
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
