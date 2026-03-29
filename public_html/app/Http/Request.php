<?PHP

declare(strict_types=1);

namespace app\Http;

class Request extends Superglobal
{
    private string $method;
    private array $headers;
    private array $parameters;

    public function __construct(string $method, array $headers, ...$parameters)
    {
        parent::__construct();
        $this->method = $method;
        $this->headers = $headers;
        $this->parameters = $parameters;
    }

    public static function capture(): self
    {
        $headers = (getallheaders() ?? []);
        $method = (REQUEST_METHOD ?? 'GET');
        $parameters = Router::extract((REQUEST_URI ?? ''), $method);

        return new self((string) $method, (array) $headers, ...$parameters);
    }

    public function getMethod(): string
    {
        return (string) $this->method;
    }

    public function getHeader(string $key): mixed
    {
        return ($this->headers[$key] ?? null);
    }

    public function getParameter(string $key, ?string $default = null): mixed
    {
        return ($this->parameters[$key] ?? $default);
    }
}
