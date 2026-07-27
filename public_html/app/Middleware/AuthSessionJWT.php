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

    public function handle(Request $request, callable $next): Response
    {
        $auth = $this->application->get('authService');
        if ($auth->getAuthenticatedUserId() === null) {
            return $next($request);
        }

        $authorizationHeader = $request->getHeader('Authorization')
            ?? $request->getHeader('authorization')
            ?? $request->server('HTTP_AUTHORIZATION');
        if ($authorizationHeader === null || trim((string) $authorizationHeader) === '') {
            return $next($request);
        }

        $jwtService = $this->application->get('jwtService');
        if (($jwtService instanceof JWTService) === false) {
            throw new \RuntimeException('jwtService service is not available.');
        }

        if (preg_match('/Bearer\s+(\S+)/', $authorizationHeader, $matches) === false
            || count($matches) < 2) {
            return new Response('Token not found in request', 400);
        }

        $authorizationResult = $jwtService->authorize($matches[1]);
        if (($authorizationResult['status'] ?? null) === 'unauthorized') {
            $description = $authorizationResult['description'];
            return new Response(
                sprintf('401 Unauthorized: %s', $description),
                401,
                [sprintf('WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="%s"', $description)]
            );
        }

        $auth->storeJwtToken($authorizationResult['token']);
        return $next($request);
    }
}
