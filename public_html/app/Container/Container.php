<?PHP

declare(strict_types=1);

namespace app\Container;

class Container
{
    /**
     * Summary of container
     * @var array
     */
    private array $container;

    /**
     * Summary of __construct
     * $this->container = [];
     */
    public function __construct()
    {
        $this->container = [];
    }

    /**
     * Summary of make
     * @param string $className
     * @throws \Exception
     * @return object|null
     */
    public function make(string $className): ?object
    {
        if ((bool) class_exists($className) === true) {
            return new $className($this);
        }

        if ((bool) class_exists($className) === false) {
            throw new \Exception("Class " . htmlspecialchars($className) . " not found.");
        }
        return null;
    }

    /**
     * Summary of addService
     * @param string $name
     * @param mixed $service
     * @return void
     */
    public function addService(string $name, ?object $service): void
    {
        $this->container[$name] = $service;
    }

    /**
     * Summary of get
     * @param string $name
     * @return object|null
     */
    public function get(string $name): ?object
    {
        if (array_key_exists($name, $this->container) === true) {
            if (is_callable($this->container[$name]) === true) {
                return $this->container[$name]();
            }

            return $this->container[$name];
        }

        return null;
    }
}
