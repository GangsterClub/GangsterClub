<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;

class SecurityHeaders
{
    private Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $headers = [
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'Permissions-Policy: geolocation=(), microphone=(), camera=()',
            "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; object-src 'none'; frame-ancestors 'none'"
        ];

        if ($request->server('HTTPS') !== null || strtolower((string) $request->server('HTTP_X_FORWARDED_PROTO', '')) === 'https') {
            $headers[] = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $header) {
            $response = $response->withHeader($header);
        }

        return $response;
    }
}
