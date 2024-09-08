<?PHP

declare(strict_types=1);

namespace app\Http;

use Symfony\Component\Yaml\Yaml;
use app\Business\YamlCacheService as RoutesCache;

class Router
{
    /**
     * Summary of routes
     * @var array
     */
    private static array $routes = [];

    /**
     * Summary of methods
     * @var array
     */
    private static array $methods = ['GET'];

    /**
     * Summary of load
     * @param string $yaml
     * @return void
     */
    public function load(string $yaml): void
    {
        $cachedYaml = RoutesCache::getPath($yaml);
        $cachedRoutes = RoutesCache::loadCache($cachedYaml);
        if (empty($cachedRoutes) === false && is_array($cachedRoutes) === true) {
            static::$routes = array_merge(static::$routes, $cachedRoutes);
            return;
        }

        $routes = $this->parse($yaml);
        static::$routes = array_merge(static::$routes, $routes);
        RoutesCache::storeCache($cachedYaml, $routes);
    }

    /**
     * Summary of parse
     * @param string $yaml
     * @param array $parsed
     * @return array
     */
    private function parse(string $yaml, array $parsed = []): array
    {
        if ((bool) function_exists('yaml_parse_file') === true) {
            $routes = @yaml_parse_file($yaml) ?: $parsed;
        }

        if ((bool) class_exists('Yaml') === true && $routes === []) {
            $routes = @Yaml::parseFile($yaml) ?: $parsed;
        }

        return $routes ?: $parsed;
    }

    /**
     * Summary of extract
     * @param string $url
     * @param string $method
     * @param array $parameters
     * @return array
     */
    public static function extract(string $url, string $method, ...$parameters): array
    {
        if ((bool) ($routeData = self::matchRoute($url, $method)) === true) {
            $parameters['methods'] = ($routeData['methods'] ?? static::$methods);
            $parameters = self::extractParameters($url, $routeData['path'], ...$parameters);
        }

        return (array) $parameters;
    }

    /**
     * Summary of match
     * @param string $url
     * @param string $method
     * @return Route|null
     */
    public function match(string $url, string $method): ?Route
    {
        if ((bool) ($routeData = self::matchRoute($url, $method)) === true) {
            $allowedMethods = ($routeData['methods'] ?? static::$methods);
            return new Route($url, $routeData['controller'], $allowedMethods);
        }

        return null;
    }

    /**
     * Summary of matchRoute
     * @param string $url
     * @param string $method
     * @return array|null
     */
    private static function matchRoute(string $url, string $method): ?array
    {
        $filteredRoutes = array_filter(
            static::$routes,
            function ($routeData) use ($url, $method) {
                $pattern = self::replacePattern($routeData['path']);
                return preg_match($pattern, $url) && in_array($method, ($routeData['methods'] ?? static::$methods));
            }
        );
        if (empty($filteredRoutes) === false) {
            return reset($filteredRoutes);
        }

        return null;
    }

    /**
     * Summary of replacePattern
     * @param string $route
     * @return string
     */
    private static function replacePattern(string $route): string
    {
        $route = preg_replace_callback(
            '/\{([^}]+)\}/',
            function ($matches) {
                return '(?P<' . preg_quote($matches[1], '/') . '>[^/]+)';
            },
            APP_BASE . $route
        );
        $pattern = '~^' . $route . '$~i';
        return (string) $pattern;
    }

    /**
     * Summary of extractParameters
     * @param string $url
     * @param string $routePath
     * @param array $parameters
     * @return array
     */
    private static function extractParameters(string $url, string $routePath, ...$parameters): array
    {
        $routePattern = self::replacePattern($routePath);
        $urlParts = parse_url($url);
        $path = isset($urlParts['path']) === true ? $urlParts['path'] : '/';
        if ((bool) (preg_match($routePattern, $path, $matches)) === true) {
            foreach ($matches as $key => $value) {
                if (is_numeric($key) === false) {
                    $parameters[$key] = $value;
                }
            }
        }

        return (array) $parameters;
    }
}
