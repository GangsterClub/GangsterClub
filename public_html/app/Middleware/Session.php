<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

class Session
{
    protected Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function handle(Request $request, callable $next) : ?Response
    {
        $sessionService = $this->application->get('sessionService');
        $sessionService->start('myApp');
        $response = $next($request);
        $sessionService->writeClose();
        return $response;
    }
}
