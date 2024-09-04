<?PHP

declare(strict_types=1);

namespace app\Http;

class Route
{
    /**
     * Summary of path
     * @var string
     */
    private string $path;

    /**
     * Summary of controller
     * @var string
     */
    private string $controller;

    /**
     * Summary of methods
     * @var array
     */
    private array $methods;

    /**
     * Summary of __construct
     * @param string $path
     * @param string $controller
     * @param array $methods
     */
    public function __construct(string $path, string $controller, array $methods)
    {
        $this->path = $path;
        $this->controller = $controller;
        $this->methods = $methods;
    }

    /**
     * Summary of getPath
     * @return string
     */
    public function getPath(): string
    {
        return (string) $this->path;
    }

    /**
     * Summary of getController
     * @return string
     */
    public function getController(): string
    {
        return (string) $this->controller;
    }

    /**
     * Summary of getMethods
     * @return array
     */
    public function getMethods(): array
    {
        return (array) $this->methods;
    }
}
