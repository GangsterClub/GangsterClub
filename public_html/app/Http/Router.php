<?PHP

declare(strict_types=1);

namespace app\Http;

class Router
{
    private array $routes = [];

    public function __construct()
    {
        $yamlFilePath = __DIR__ . '/../config/routes.yaml';
        $this->routes = yaml_parse_file($yamlFilePath) ?? [];
    }

    public function getPattern($url, $method): ?string
    {
        $routeData = $this->matchRoute($url, $method);
        if ($routeData)
            return $this->replacePattern($routeData['path']);

        return null; // Return null if no match is found.
    }

    public function match($url, $method): ?Route
    {
        $routeData = $this->matchRoute($url, $method);
        if ($routeData) {
            $allowedMethods = $routeData['methods'] ?? ['GET'];
            return new Route($url, $routeData['controller'], $allowedMethods);
        }
        return null; // Return null if no match is found.
    }

    private function matchRoute($url, $method): ?array
    {
        $filteredRoutes = array_filter($this->routes, function ($routeData) use ($url, $method) {
            $pattern = $this->replacePattern($routeData['path']);
            return preg_match($pattern, $url) && in_array($method, $routeData['methods'] ?? ['GET']);
        });
        if (!empty($filteredRoutes))
            return reset($filteredRoutes);

        return null; // Return null if no match is found.
    }

    private function replacePattern($route): string
    {
        $route = APP_BASE . $route;
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $route);
        $pattern = '~^' . $pattern . '$~i';
        return (string) $pattern;
    }
}
