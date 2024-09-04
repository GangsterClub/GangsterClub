<?PHP

declare(strict_types=1);

namespace app\Business;

class YamlCacheService
{
    /**
     * Summary of maxAge
     * @var int
     */
    private static int $maxAge = (2 * 60 * 60);

    /**
     * Summary of isCache
     * @param string $cachedYaml
     * @return bool
     */
    private static function isCache(string $cachedYaml): bool
    {
        return str_ends_with($cachedYaml, '.yaml') === true ? strpos($cachedYaml, '/cache/') !== false : false;
    }

    /**
     * Summary of isCacheable
     * @param string $yaml
     * @return bool
     */
    private static function isCacheable(string $yaml): bool
    {
        return str_ends_with($yaml, '.yaml') === true ? strpos($yaml, '/resources/') !== false : false;
    }

    /**
     * Summary of getPath
     * @param string $yaml
     * @return string
     */
    public static function getPath(string $yaml): string
    {
        $dev = (defined('DEVELOPMENT') === false || defined('DEVELOPMENT') === true && DEVELOPMENT == false);
        return $dev === true && self::isCacheable($yaml) === true ? str_replace('/resources/', '/cache/', $yaml) : $yaml;
    }

    /**
     * Summary of loadCache
     * @param string $cachedYaml
     * @return array
     */
    public static function loadCache(string $cachedYaml): array
    {
        if (file_exists($cachedYaml) === true && self::isCache($cachedYaml) === true) {
            $cachedRoutes = file_get_contents($cachedYaml);
            $arr = @json_decode($cachedRoutes, true);
            if ($arr !== false && $cachedRoutes !== false) {
                $maxAge = defined('APP_MAX_AGE') === true ? (int) APP_MAX_AGE : static::$maxAge;
                if ((time() - filemtime($cachedYaml)) > $maxAge) {
                    unlink($cachedYaml);
                }

                return $arr ?: [];
            }
        }

        return [];
    }

    /**
     * Summary of storeCache
     * @param string $cachedYaml
     * @param array $fileContents
     * @return void
     */
    public static function storeCache(string $cachedYaml, array $fileContents): void
    {
        if (self::isCache($cachedYaml) === true) {
            if (is_dir(dirname($cachedYaml)) === false) {
                mkdir(dirname($cachedYaml), 0755, true);
            }

            file_put_contents($cachedYaml, json_encode($fileContents));
        }
    }
}
