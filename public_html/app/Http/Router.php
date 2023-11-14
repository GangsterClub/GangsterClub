<?PHP

declare(strict_types=1);

namespace app\Http;

class Router
{
    private static array $routes = [];

    public function load(string $yaml): void
    {
        if(!file_exists($yaml))
            return;

        $cachedYaml = RoutesCache::getPath($yaml);
        $cachedRoutes = RoutesCache::loadCache($cachedYaml);
        if(!empty($cachedRoutes))
        {
            static::$routes = array_merge(static::$routes, $cachedRoutes);
            return;
        }
        $routes = yaml_parse_file($yaml) ?: [];
        static::$routes = array_merge(static::$routes, $routes);
        RoutesCache::storeCache($cachedYaml, $routes);
    }

    public static function getPattern(string $url, string $method) : ?string
    {
        $routeData = self::matchRoute($url, $method);
        if($routeData)
            return self::replacePattern($routeData['path']);

        return null; // Return null if no match is found.
    }

    public function match(string $url, string $method) : ?Route
    {
        $routeData = self::matchRoute($url, $method);
        if($routeData)
        {
            $allowedMethods = $routeData['methods'] ?? ['GET'];
            return new Route($url, $routeData['controller'], $allowedMethods);
        }
        return null; // Return null if no match is found.
    }

    private static function matchRoute(string $url, string $method) : ?array
    {
        $filteredRoutes = array_filter(static::$routes, function ($routeData) use ($url, $method) {
            $pattern = self::replacePattern($routeData['path']);
            return preg_match($pattern, $url) && in_array($method, $routeData['methods'] ?? ['GET']);
        });
        if(!empty($filteredRoutes))
            return reset($filteredRoutes);

        return null; // Return null if no match is found.
    }

    private static function replacePattern(string $route) : string
    {
        $route = APP_BASE . $route;
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $route);
        $pattern = '~^' . $pattern . '$~i';
        return (string) $pattern;
    }
}
