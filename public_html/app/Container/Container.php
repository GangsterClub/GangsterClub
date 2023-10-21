<?PHP

declare(strict_types=1);

namespace app\Container;

class Container implements ContainerInterface
{
    private array $container;

    public function __construct()
    {
        $this->container = [];
    }
    
    public function make($className) : ?object
    {
        if (class_exists($className))
            return new $className($this);

        throw new \Exception("Class $className not found.");
    }

    public function addService($name, $service) : void
    {
        $this->container[$name] = $service;
    }
    
    public function get($name) : ?object
    {
        if (array_key_exists($name, $this->container)) {
            if (is_callable($this->container[$name])) {
                return $this->container[$name](); // Execute the closure and return its result
            }
            return $this->container[$name];
        }
    
        return null;
    }
}
