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
        $sessionService = $this->application->get('sessionService');
        $sessionService->start('myApp');
        $response = $next($request);
        $sessionService->writeClose();
        return $response;
    }
}
