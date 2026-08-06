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
        $container->addService('router', fn(): Router => new Router());
        $container->addService('translationService', fn(): TranslationService => new TranslationService());
    }
}
