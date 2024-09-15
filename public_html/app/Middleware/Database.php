<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

class Database
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
     * @return \app\Http\Response|callable
     */
    public function handle(Request $request, callable $next): ?Response
    {
        $this->application->addService('db', new \src\Data\Connection());
        return $next($request);
    }
}
