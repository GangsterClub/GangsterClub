<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Http\Request;
use app\Http\Response;
use app\Service\JWTService;

class BearerAuthJWT
{
    public function __construct(private readonly JWTService $jwtService)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $authorizationHeader = $request->getHeader('Authorization')
            ?? $request->getHeader('authorization')
            ?? $request->server('HTTP_AUTHORIZATION');
        if ($authorizationHeader === null || trim((string) $authorizationHeader) === '') {
            return new Response('401 Unauthorized: Bearer token required', 401, ['WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8"']);
        }

        if (preg_match('/Bearer\s+(\S+)/', $authorizationHeader, $matches) === false
            || count($matches) < 2) {
            return new Response('401 Unauthorized: Bearer token required', 401, ['WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8"']);
        }

        $authorizationResult = $this->jwtService->authorize($matches[1]);
        if (($authorizationResult['status'] ?? null) === 'unauthorized') {
            $description = $authorizationResult['description'];
            return new Response(
                sprintf('401 Unauthorized: %s', $description),
                401,
                [sprintf('WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="%s"', $description)]
            );
        }

        return $next($request);
    }
}
