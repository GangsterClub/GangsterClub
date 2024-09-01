<?PHP

declare(strict_types=1);

namespace app\Http;

class Request
{
    private array $parameters;
    private array $headers;
    private string $method;

    public function __construct(array $parameters, array $headers, string $method)
    {
        $this->parameters = $parameters;
        $this->headers = $headers;
        $this->method = $method;
    }

    public static function capture(): self
    {
        $headers = getallheaders() ?? [];
        $method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'GET';
        $parameters = Router::extract((REQUEST_URI ?? ''), $method);

        return new self((array) $parameters, (array) $headers, (string) $method);
    }

    public function getParameter(string $key, ?string $default = null): mixed
    {                
        return $this->parameters[$key] ?? $default;
    }

    public function getHeader(string $key): mixed
    {
        return $this->headers[$key] ?? null;
    }

    public function getMethod(): string
    {
        return (string) $this->method;
    }
}
