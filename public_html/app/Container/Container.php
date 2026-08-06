<?PHP

declare(strict_types=1);

namespace app\Container;

class Container
{
    private array $container = [];

    public function register(ServiceProvider $provider): void
    {
        $provider->register($this);
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public function getRegisteredService(string $name, string $className): object
    {
        $service = $this->get($name);
        if (($service instanceof $className) === false) {
            throw new \RuntimeException($name . ' service is not available.');
        }

        return $service;
    }

    public function make(string $className): object
    {
        if (class_exists($className) === false) {
            throw new \Exception("Class " . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . " not found.");
        }

        return new $className($this);
    }

    public function addService(string $name, object|callable|null $service): void
    {
        $this->container[$name] = $service;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->container);
    }

    public function get(string $name): ?object
    {
        if (array_key_exists($name, $this->container) === false) {
            return null;
        }

        $service = $this->container[$name];
        if (is_object($service) === true && ($service instanceof \Closure) === false) {
            return $service;
        }

        if (is_callable($service) === true) {
            $service = $service();
            $this->container[$name] = $service;
        }

        if ($service !== null && is_object($service) === false) {
            throw new \RuntimeException($name . ' service did not resolve to an object.');
        }

        return $service;
    }
}
