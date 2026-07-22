<?PHP

declare(strict_types=1);

namespace app\Service;

use app\Http\Request;

class CsrfService
{
    public const FIELD_NAME = '_csrf_token';
    private const SESSION_KEY = '_csrf_token';

    private SessionService $session;

    public function __construct(SessionService $session)
    {
        $this->session = $session;
    }

    public function getToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (is_string($token) === false || $token === '') {
            return $this->rotateToken();
        }

        return $token;
    }

    public function rotateToken(): string
    {
        $token = $this->generateToken();
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function getFieldName(): string
    {
        return self::FIELD_NAME;
    }

    public function renderInput(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            htmlspecialchars(self::FIELD_NAME, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8')
        );
    }

    public function isValidRequest(Request $request): bool
    {
        $providedToken = $this->getSubmittedToken($request);

        return is_string($providedToken) === true
            && $providedToken !== ''
            && hash_equals($this->getToken(), $providedToken);
    }

    public function isStateChangingMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function getSubmittedToken(Request $request): mixed
    {
        $headerToken = $request->getHeader('X-CSRF-Token') ?? $request->getHeader('X-Csrf-Token') ?? $request->getHeader('x-csrf-token');
        if (is_string($headerToken) === true && $headerToken !== '') {
            return $headerToken;
        }

        $method = strtoupper($request->getMethod());
        return match ($method) {
            'POST' => $request->post(self::FIELD_NAME),
            'PUT' => $request->put(self::FIELD_NAME),
            'PATCH' => $request->patch(self::FIELD_NAME),
            'DELETE' => $request->delete(self::FIELD_NAME),
            default => null,
        };
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
