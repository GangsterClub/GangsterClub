<?PHP

declare(strict_types=1);

namespace app\Container;

class Application extends Container
{
    private ?string $directory;

    public function __construct($dir)
    {
        $this->directory = $dir;
        $this->registerServices();
    }
    
    private function registerServices()
    {
        $this->addService('router', function () {
            return new \app\Http\Router();
        });
    }
}
