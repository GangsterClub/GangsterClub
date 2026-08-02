<?PHP

declare(strict_types=1);

namespace app\Http;

class Request extends Superglobal
{
    private string $method;
    private string $uri;
    private string $path;
    private array $headers;
    private array $routeParameters = [];

    public function __construct(string $method, string $uri, array $headers)
    {
        parent::__construct();

        $path = parse_url($uri, PHP_URL_PATH);
        $this->method = $method;
        $this->uri = $uri;
        $this->path =  is_string($path) && $path !== '' ? $path : '/';
        $this->headers = $headers;
    }

    public static function capture(): self
    {
        $method = (REQUEST_METHOD ?? 'GET');
        $uri = (REQUEST_URI ?? '/');
        $headers = (getallheaders() ?? []);

        return new self((string) $method, (string) $uri, (array) $headers);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHeader(string $key): mixed
    {
        return ($this->headers[$key] ?? null);
    }

    public function getRouteParameter(string $key, ?string $default = null): mixed
    {
        return ($this->routeParameters[$key] ?? $default);
    }

    public function setRouteParameters(array $parameters): void
    {
        $this->routeParameters = $parameters;
    }
}
