<?PHP

declare(strict_types=1);

namespace app\Http;

use Symfony\Component\Yaml\Yaml;
use app\Service\YamlCacheService as RoutesCache;

class Router
{
    private static array $routes = [];

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
     * Summary of parse make this its own class or YamlCacheService class function, if more parsing is required
     * @param string $yaml
     * @param array $parsed
     * @return array
     */
    private function parse(string $yaml, array $parsed = []): array
    {
        if (function_exists('yaml_parse_file') === true) {
            $routes = file_exists($yaml) === true ? @yaml_parse_file($yaml) : $parsed;
        }

        if (class_exists('\Symfony\Component\Yaml\Yaml') === true && isset($routes) === false) {
            $routes = file_exists($yaml) === true ? @Yaml::parseFile($yaml) : $parsed;
        }

        return $routes ?? $parsed;
    }

    public static function path(string $name, array $parameters = []): string
    {
        $route = static::$routes[$name] ?? null;

        if ($route === null || isset($route['path']) === false) {
            return rtrim(WEB_ROOT, '/') . '/404';
        }

        $path = (string) $route['path'];
        $usedParameters = [];

        $path = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use ($name, $parameters, &$usedParameters): string {
                $parameter = $matches[1];

                if (array_key_exists($parameter, $parameters) === false) {
                    throw new \InvalidArgumentException(
                        sprintf('Missing parameter "%s" for route "%s".', $parameter, $name)
                    );
                }

                $usedParameters[] = $parameter;

                return rawurlencode((string) $parameters[$parameter]);
            },
            $path
        );

        $unusedParameters = array_diff(array_keys($parameters), $usedParameters);

        if ($unusedParameters !== []) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unused parameter(s) for route "%s": %s.',
                    $name,
                    implode(', ', $unusedParameters)
                )
            );
        }

        return rtrim(WEB_ROOT, '/') . '/' . ltrim($path, '/');
    }

    public function match(string $path, string $method): ?RouteMatch
    {
        if (($routeData = self::matchRoute($path, $method)) === null) {
            return null;
        }

        $allowedMethods = ($routeData['methods'] ?? []);
        $parameters = self::extractParameters($path, $routeData['path']);
        $route = new Route($path, $routeData['controller'], $allowedMethods);
        return new RouteMatch($route, $parameters);
    }

    private static function matchRoute(string $url, string $method): ?array
    {
        $filteredRoutes = array_filter(
            static::$routes,
            function ($routeData) use ($url, $method) {
                $pattern = self::replacePattern($routeData['path']);
                return preg_match($pattern, $url) && in_array($method, ($routeData['methods'] ?? []));
            }
        );

        if (empty($filteredRoutes) === false) {
            return reset($filteredRoutes);
        }

        return null;
    }

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

    private static function extractParameters(string $path, string $routePath): array
    {
        $pattern = self::replacePattern($routePath);

        if (preg_match($pattern, $path, $matches) !== 1) {
            return [];
        }

        return array_filter(
            $matches,
            static fn (string|int $key): bool => \is_int($key) === false,
            ARRAY_FILTER_USE_KEY,
        );
    }
}
