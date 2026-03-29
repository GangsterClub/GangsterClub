<?PHP

declare(strict_types=1);

namespace app\Http;

class Route
{
    private string $path;
    private string $controller;
    private array $methods;

    public function __construct(string $path, string $controller, array $methods)
    {
        $this->path = $path;
        $this->controller = $controller;
        $this->methods = $methods;
    }

    public function getPath(): string
    {
        return (string) $this->path;
    }

    public function getController(): string
    {
        return (string) $this->controller;
    }

    public function getMethods(): array
    {
        return (array) $this->methods;
    }
}
