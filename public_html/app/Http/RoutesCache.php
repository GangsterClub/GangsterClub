<?PHP

declare(strict_types=1);

namespace app\Http;

class RoutesCache
{
    private static int $maxAge = 2 * 60 * 60;

    private static function isCache(string $cachedYaml) : bool
    {
        return strpos($cachedYaml, '/cache/') !== false;
    }

    private static function isCacheable(string $yaml) : bool
    {
        return strpos($yaml, '/config/') !== false;
    }

    public static function getPath(string $yaml): string
    {
        $dev = (!defined('APP_DEVELOPMENT') || defined('APP_DEVELOPMENT') && DEVELOPMENT == false);
        return $dev && self::isCacheable($yaml) ? str_replace('/config/', '/cache/', $yaml) : $yaml;
    }

    public static function loadCache(string $cachedYaml) : array
    {
        if(file_exists($cachedYaml) && self::isCache($cachedYaml))
        {
            $cachedRoutes = file_get_contents($cachedYaml);
            $arr = @unserialize($cachedRoutes);
            if($arr !== false && $cachedRoutes !== false)
            {
                $maxAge = defined('APP_MAX_AGE') ? (int)APP_MAX_AGE : static::$maxAge;
                if(time()-filemtime($cachedYaml) > $maxAge)
                    unlink($cachedYaml); // Delete cached routes if older than $maxAge.

                return $arr ?: [];
            }
        }
        return [];
    }

    public static function storeCache(string $cachedYaml, array $routes) : void
    {
        if(self::isCache($cachedYaml))
            file_put_contents($cachedYaml, serialize($routes));
    }
}
