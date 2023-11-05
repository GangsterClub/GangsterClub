<?PHP

declare(strict_types=1);

namespace app\Container;

use app\Http\Router;

class Application
{
    private ?string $directory;
    private Router $router;
        
    public function __construct($dir)
    {
        define('DOC_ROOT', $this->directory = $dir);
        define('APP_BASE', $this->getBase());
        define('PROTOCOL', 'http'.(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://');
        define('WEB_ROOT', PROTOCOL . $_SERVER['HTTP_HOST'] . APP_BASE . (!empty(APP_BASE) ? '/' : ''));
        $this->router = new Router();
    }

    public function make($className) : ?object
    {
        if (class_exists($className))
            return new $className($this);

        throw new \Exception("Class $className not found.");
        return null;
    }

    public function get($prop) : mixed
    {
        if(isset($this->$prop))
        {
            if (is_callable($this->$prop))
                return $this->$prop();

            return $this->$prop;
        }
        return null;
    }

    private function getBase() : string
    {
        return str_replace('\\', '/',
            str_replace(
                str_replace('/', '\\', $_SERVER['DOCUMENT_ROOT']), '',
                str_replace('/', '\\',
                    $this->directory
                )
            )
        );
    }
}
