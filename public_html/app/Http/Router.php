<?PHP

declare(strict_types=1);

namespace app\Http;

class Router
{
    private static $routes = [];

    public function __construct()
    {
        static::$routes = $_SESSION['routes'] = $_SESSION['routes'] ??
            yaml_parse_file(__DIR__ . '/../config/routes.yaml') ?? [];
    }

    public function __destruct()
    {
        if(defined('DEVELOPMENT') && DEVELOPMENT === true)
            $_SESSION['routes'] = null;
    }

    public static function getPattern($url, $method) : ?string
    {
        $routeData = self::matchRoute($url, $method);
        if ($routeData)
            return self::replacePattern($routeData['path']);

        return null; // Return null if no match is found.
    }

    public function match($url, $method) : ?Route
    {
        $routeData = self::matchRoute($url, $method);
        if ($routeData) {
            $allowedMethods = $routeData['methods'] ?? ['GET'];
            return new Route($url, $routeData['controller'], $allowedMethods);
        }
        return null; // Return null if no match is found.
    }

    private static function matchRoute($url, $method) : ?array // Used in Request & Kernel
    {
        $filteredRoutes = array_filter(static::$routes, function ($routeData) use ($url, $method) {
            $pattern = self::replacePattern($routeData['path']);
            return preg_match($pattern, $url) && in_array($method, $routeData['methods'] ?? ['GET']);
        });
        if (!empty($filteredRoutes))
            return reset($filteredRoutes);

        return null; // Return null if no match is found.
    }

    private static function replacePattern($route) : string // Used in Request & Kernel
    {
        $route = APP_BASE . $route;
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $route);
        $pattern = '~^' . $pattern . '$~i';
        return (string) $pattern;
    }
}
