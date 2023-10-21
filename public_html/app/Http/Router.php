<?PHP

declare(strict_types=1);

namespace app\Http;

class Router
{
    private array $routes;

    public function __construct()
    {
        $yamlFilePath = __DIR__ . '/../config/routes.yaml';
        $this->routes = yaml_parse_file($yamlFilePath) ?? [];
    }

    public function getRoutePattern($url, $method) : ?string
    {
        foreach ($this->routes as $route => $routeData)
        {
            $pattern = $this->replaceRoutePattern($route);
            $allowedMethods = $routeData['methods'] ?? ['GET'];

            if (preg_match($pattern, $url, $matches) && in_array($method, $allowedMethods))
                return $pattern;
        }
        return null; // Return null if no match is found.
    }

    public function match($url, $method) : ?Route
    {
        $routeData = $this->matchRoute($url, $method);
        $allowedMethods = $routeData['methods'] ?? ['GET'];
        if ($routeData && in_array($method, $allowedMethods))
            return new Route(array_search($routeData, $this->routes), $routeData['controller'], $allowedMethods);

        return null; // Return null if no match is found.
    }

    private function matchRoute($url, $method) : ?array
    {
        foreach ($this->routes as $route => $routeData)
        {
            $pattern = $this->replaceRoutePattern($route);
            $allowedMethods = $routeData['methods'] ?? ['GET'];

            if (preg_match($pattern, $url, $matches) && in_array($method, $allowedMethods))
                return $routeData;
        }
        return null;
    }

    private function replaceRoutePattern($route) : string
    {
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $route);
        $pattern = '~^' . $pattern . '$~i';
        return (string)$pattern;
    }
}
