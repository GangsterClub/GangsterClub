<?PHP

declare(strict_types=1);

namespace app\Http;

class Request
{
    private array $parameters;
    private array $headers;
    private string $method;

    public function __construct($parameters, $headers, $method)
    {
        $this->parameters = $parameters;
        $this->headers = $headers;
        $this->method = $method;
    }

    public static function capture(Router $router) : self
    {
        $headers = getallheaders();
        $method = $_SERVER['REQUEST_METHOD'];
        $routePattern = $router->getPattern($_SERVER['REQUEST_URI'], $method);
        $parameters = [];

        if ($routePattern)
            $parameters = self::extractParameters($routePattern);

        return new self($parameters, $headers, $method);
    }

    private static function extractParameters($routePattern) : array
    {
        $url = $_SERVER['REQUEST_URI'];
        $urlParts = parse_url($url);
        $path = isset($urlParts['path']) ? $urlParts['path'] : '/';
        $parameters = [];

        if (preg_match($routePattern, $path, $matches)) {
            foreach ($matches as $key => $value) {
                if (!is_numeric($key))
                    $parameters[$key] = $value;
            }
        }
        return (array)$parameters;
    }

    public function getParameter($key, $default = null) : ?string
    {
        return $this->parameters[$key] ?? $default;
    }

    public function getHeader($key) : ?array
    {
        return $this->headers[$key] ?? null;
    }

    public function getMethod() : string
    {
        return (string)$this->method;
    }
}
