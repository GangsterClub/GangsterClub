<?PHP

declare(strict_types=1);

namespace app\Container;

interface ServiceProvider
{
    public function register(Container $container): void;
}
