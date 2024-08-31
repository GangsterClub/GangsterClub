<?PHP

declare(strict_types=1);

namespace app\Container;

class Container
{
    private array $container;

    public function __construct()
    {
        $this->container = [];
    }

    public function make(string $className): ?object
    {
        if (class_exists($className)) {
            return new $className($this);
        }
        throw new \Exception("Class ".htmlspecialchars($className)." not found.");
        return null;
    }

    public function addService(string $name, ?object $service): void
    {
        $this->container[$name] = $service;
    }

    public function get(string $name): ?object
    {
        if (array_key_exists($name, $this->container)) {
            if (is_callable($this->container[$name])) {
                return $this->container[$name]();
            }
            return $this->container[$name];
        }
        return null;
    }
}
