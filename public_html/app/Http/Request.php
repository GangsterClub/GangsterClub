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

    public static function capture() : self
    {
        $headers = getallheaders();
        $method = $_SERVER['REQUEST_METHOD'];
        $parameters = Router::extract($_SERVER['REQUEST_URI'], $method);

        return new self($parameters, $headers, $method);
    }

    public function getParameter(string $key, ?string $default = null) : ?string
    {
        return $this->parameters[$key] ?? $default;
    }

    public function getHeader(string $key) : ?array
    {
        return $this->headers[$key] ?? null;
    }

    public function getMethod() : string
    {
        return (string)$this->method;
    }
}
