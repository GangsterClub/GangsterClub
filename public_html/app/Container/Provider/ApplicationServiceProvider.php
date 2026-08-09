<?PHP

declare(strict_types=1);

namespace app\Container\Provider;

use app\Container\Container;
use app\Container\ServiceProvider;
use app\Http\Router;
use app\Service\TranslationService;

final class ApplicationServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(\app\Http\Router::class, fn(): Router => new Router());
        $container->set(\app\Service\TranslationService::class, fn(): TranslationService => new TranslationService());
    }
}
