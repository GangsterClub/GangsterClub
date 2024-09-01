<?PHP

declare(strict_types=1);

namespace app\Http;

use app\Business\YamlCacheService as RoutesCache;

class Router
{
    private static array $routes = [];
    private static array $methods = ['GET'];

    public function load(string $yaml): void
    {
        $cachedYaml = RoutesCache::getPath($yaml);
        $cachedRoutes = RoutesCache::loadCache($cachedYaml);
        if (!empty($cachedRoutes) && is_array($cachedRoutes)) {
            static::$routes = array_merge(static::$routes, $cachedRoutes);
            return;
        }

        $routes = yaml_parse_file($yaml) ?: [];
        static::$routes = array_merge(static::$routes, $routes);
        RoutesCache::storeCache($cachedYaml, $routes);
    }

    public static function extract(string $url, string $method, array $parameters = []): array
    {
        if ((bool)($routeData = self::matchRoute($url, $method)) === true) {
            $parameters['methods'] = $routeData['methods'] ?? static::$methods;
            $parameters = self::extractParameters($url, $routeData['path'], $parameters);
        }

        return (array)$parameters;
    }

    public function match(string $url, string $method): ?Route
    {
        if ((bool)($routeData = self::matchRoute($url, $method)) === true) {
            $allowedMethods = $routeData['methods'] ?? static::$methods;
            return new Route($url, $routeData['controller'], $allowedMethods);
        }

        return null; // Return null if no match is found.
    }

    private static function matchRoute(string $url, string $method): ?array
    {
        $filteredRoutes = array_filter(static::$routes, function ($routeData) use ($url, $method) {
            $pattern = self::replacePattern($routeData['path']);
            return preg_match($pattern, $url) && in_array($method, $routeData['methods'] ?? static::$methods);
        });
        if (empty($filteredRoutes) === false) {
            return reset($filteredRoutes);
        }

        return null; // Return null if no match is found.
    }

    private static function replacePattern(string $route): string
    {
        $route = preg_replace_callback('/\{([^}]+)\}/', function($matches) {
            return '(?P<'.preg_quote($matches[1], '/').'>[^/]+)';
        }, APP_BASE.$route);
        $pattern = '~^'.$route.'$~i';
        return (string) $pattern;
    }

    private static function extractParameters(string $url, string $routePath, array $parameters = []): array
    {
        $routePattern = self::replacePattern($routePath);
        $urlParts = parse_url($url);
        $path = isset($urlParts['path']) ? $urlParts['path'] : '/';
        if ((bool) (preg_match($routePattern, $path, $matches)) === true) {
            foreach ($matches as $key => $value) {
                if (is_numeric($key) === false) {
                    $parameters[$key] = $value;
                }
            }
        }

        return (array)$parameters;
    }
}
