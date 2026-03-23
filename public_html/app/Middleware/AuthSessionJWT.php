<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\JWTService;

class AuthSessionJWT
{
    protected Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function handle(Request $request, callable $next): ?Response
    {
        $auth = $this->application->get('authService');
        if ($auth->getAuthenticatedUserId() === null) {
            return $next($request);
        }

        $jwtService = new JWTService($this->application);
        $authorizationResult = $jwtService->authorizeRequest($request);
        if ($authorizationResult instanceof Response) {
            $authorizationResult->send();
            $this->application->exit();
            return $authorizationResult;
        }

        return $next($request);
    }
}
