<?PHP

declare(strict_types=1);

namespace app\Middleware;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\CsrfService;

class Csrf
{
    protected Application $application;

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function handle(Request $request, callable $next): Response
    {
        $csrf = $this->application->get('csrfService');
        if ($csrf instanceof CsrfService === false) {
            $csrf = new CsrfService($this->application->get('sessionService'));
            $this->application->addService('csrfService', $csrf);
        }

        if ($csrf->isStateChangingMethod($request->getMethod()) === true && $csrf->isValidRequest($request) === false) {
            return $this->reject($request);
        }

        return $next($request);
    }

    private function reject(Request $request): Response
    {
        $translation = $this->application->get('translationService');
        $title = $translation->get('csrf-title');
        $message = $translation->get('csrf-message');
        $action = $translation->get('csrf-action');

        if ($this->expectsJson($request) === true) {
            return Response::json(
                [
                    'error' => 'csrf_token_invalid',
                    'message' => $message,
                    'action' => $action,
                ],
                419
            );
        }

        $twig = $this->application->get('twig');
        if ($twig instanceof \Twig\Environment) {
            return Response::html(
                $twig->render(
                    'error/csrf.twig',
                    [
                        'csrfError' => [
                            'title' => $title,
                            'message' => $message,
                            'action' => $action,
                            'backUrl' => $this->getBackUrl($request),
                        ],
                    ]
                ),
                419
            );
        }

        return Response::html(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), 419);
    }

    private function expectsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->getHeader('Accept'));
        $contentType = strtolower((string) $request->getHeader('Content-Type'));
        $requestedWith = strtolower((string) $request->getHeader('X-Requested-With'));

        return str_contains($accept, 'application/json') === true
            || str_contains($contentType, 'application/json') === true
            || $requestedWith === 'xmlhttprequest';
    }

    private function getBackUrl(Request $request): string
    {
        $referer = (string) ($request->getHeader('Referer') ?? '');
        if ($referer !== '' && $this->isSameOrigin($referer, WEB_ROOT) === true) {
            return $referer;
        }

        return WEB_ROOT;
    }

    private function isSameOrigin(string $url, string $origin): bool
    {
        $urlParts = parse_url($url);
        $originParts = parse_url($origin);
        if (is_array($urlParts) === false || is_array($originParts) === false) {
            return false;
        }

        $urlScheme = strtolower((string) ($urlParts['scheme'] ?? ''));
        $originScheme = strtolower((string) ($originParts['scheme'] ?? ''));
        $urlHost = strtolower((string) ($urlParts['host'] ?? ''));
        $originHost = strtolower((string) ($originParts['host'] ?? ''));

        return $urlScheme !== ''
            && $urlScheme === $originScheme
            && $urlHost !== ''
            && $urlHost === $originHost
            && $this->normalizePort($urlParts, $urlScheme) === $this->normalizePort($originParts, $originScheme);
    }

    private function normalizePort(array $parts, string $scheme): int
    {
        if (isset($parts['port']) === true) {
            return (int) $parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
    }
}
