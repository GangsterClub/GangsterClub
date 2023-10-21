<?PHP

declare(strict_types=1);

namespace app\Container;

interface ContainerInterface
{
    public function make($className) : ?object;
    public function addService($name, $service) : void;
    public function get($name) : ?object;
}
