<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\JWTService;
use app\Service\SessionService;

class AuthSessionJWT
{
    protected Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function handle(Request $request, callable $next): ?Response
    {
        $session = $this->application->get('sessionService');
        if ($session instanceof SessionService === false) {
            return $next($request);
        }

        if ((int) $session->get('UID') <= 0) {
            return $next($request);
        }

        $jwtService = new JWTService($this->application);
        $authorizationResult = $jwtService->authorizeRequest($request, $session);
        if ($authorizationResult instanceof Response) {
            $authorizationResult->send();
            $this->application->exit();
            return $authorizationResult;
        }

        return $next($request);
    }
}
