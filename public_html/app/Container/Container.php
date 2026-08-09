<?PHP

declare(strict_types=1);

namespace app\Container;

class Container
{
    /** @var array<class-string, object> */
    private array $services = [];

    public function register(ServiceProvider $provider): void
    {
        $provider->register($this);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param T|(\Closure(): T) $service
     */
    public function set(string $id, object $service): void
    {
        $this->assertServiceId($id);
        $this->assertServiceType($id, $service);
        $this->services[$id] = $service;
    }

    /** @param class-string $id */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services) === true;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        $service = $this->getOptional($id);
        if ($service !== null) {
            return $service;
        }

        throw new \RuntimeException($id . ' service is not registered.');
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T|null
     */
    public function getOptional(string $id): ?object
    {
        if ($this->has($id) === false) {
            return null;
        }

        $service = $this->services[$id];
        if ($service instanceof \Closure) {
            $service = $service();
            $this->assertServiceType($id, $service);
            $this->services[$id] = $service;
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

    private function assertServiceType(string $id, mixed $service): void
    {
        if ($service instanceof \Closure || $service instanceof $id) {
            return;
        }

        throw new \RuntimeException($id . ' service must resolve to an instance of ' . $id . '.');
    }

    private function assertServiceId(string $id): void
    {
        if (class_exists($id) === true || interface_exists($id) === true) {
            return;
        }

        throw new \InvalidArgumentException($id . ' is not a class or interface.');
    }
}
