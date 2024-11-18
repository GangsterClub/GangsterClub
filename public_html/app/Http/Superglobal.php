<?PHP

declare(strict_types=1);

namespace app\Http;

class Superglobal
{
    /**
     * Summary of globals, testing purposes
     * @var array
     */
    //protected array $globals;

    /**
     * Summary of get, idempotent, safe method
     * Unused but usefull for routes that should allow it
     * @var array
     */
    protected array $get;

    /**
     * Summary of post, unsafe method
     * @var array
     */
    protected array $post;

    /**
     * Summary of server
     * @var array
     */
    protected array $server;

    /**
     * Summary of cookie
     * @var array
     */
    protected array $cookie;

    /**
     * Summary of put, idempotent, unsafe method
     * @var array
     */
    protected array $put;

    /**
     * Summary of delete, idempotent, unsafe method
     * @var array
     */
    protected array $delete;

    /**
     * Summary of head, idempotent, safe method
     * @var array
     */
    protected array $head;

    /**
     * Summary of options, idempotent, safe method
     * @var array
     */
    protected array $options;

    /**
     * Summary of patch, unsafe method, To be treated idempotent avoids collision baddies
     * @var array
     */
    protected array $patch;

    /**
     * Summary of files, unsafe POST method
     * @var array
     */
    protected array $files;

    /**
     * Summary of request
     * @var array
     */
    protected array $request;

    /**
     * Summary of env
     * @var array
     */
    protected array $env;

    /**
     * Summary of __construct
     * $this->globals = $GLOBALS
     * $this->get = $_GET
     * $this->post = $_POST
     * $this->server = $_SERVER
     * $this->cookie = $_COOKIE
     * $this->put = PUT
     * $this->delete = DELETE
     * $this->head = HEAD
     * $this->options = OPTIONS
     * $this->patch = PATCH
     * $this->files = $_FILES
     * $this->request  = $_REQUEST
     * $this->env = $_ENV
     */
    public function __construct()
    {
        //$this->globals = filter_var_array($GLOBALS, 515); // testing purposes
        $this->get = filter_input_array(INPUT_GET, 515) ?? [];
        $this->post = filter_input_array(INPUT_POST, 515) ?? [];
        $this->server = filter_input_array(INPUT_SERVER, 515) ?? [];
        $this->cookie = filter_input_array(INPUT_COOKIE, 515) ?? [];
        $this->put = $this->parseRequestMethod('PUT');
        $this->delete = $this->parseRequestMethod('DELETE');
        $this->head = $this->parseRequestMethod('HEAD');
        $this->options = $this->parseRequestMethod('OPTIONS');
        $this->patch = $this->parseRequestMethod('PATCH');
        $this->files = filter_var_array($_FILES, 515);
        $this->request = filter_var_array($_REQUEST, 515);
        $this->env = filter_var_array($_ENV, 515);
    }

    /**
     * Summary of parseRequestMethod
     * @param string $method
     * @return array
     */
    protected function parseRequestMethod(string $method): array
    {
        if (REQUEST_METHOD === $method) {
            parse_str(file_get_contents('php://input'), $data);
            return filter_var_array($data, 515) ?? [];
        }

        return [];
    }

    /**
     * Summary of globals, Lazy globals initialization
     * @param string $key
     * @param string|int $default
     * @return int|string|array
     */
    public function globals(string $key, $default = null): mixed
    {
        // Testing purposes
        //$this->globals = filter_var_array($GLOBALS, 515);
        //return $this->globals[$key] ?? $default;
        $globals = filter_var_array($GLOBALS, 515);
        return $globals[$key] ?? $default;
    }

    /**
     * Summary of get
     * @param string $key
     * @param string|int $default
     * @return int|string|array
     */
    public function get(string $key, $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * Summary of post
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function post(string $key, $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Summary of server
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function server(string $key, $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Summary of cookie
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function cookie(string $key, $default = null): mixed
    {
        return $this->cookie[$key] ?? $default;
    }

    /**
     * Summary of put
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function put(string $key, $default = null): mixed
    {
        return $this->put[$key] ?? $default;
    }

    /**
     * Summary of delete
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function delete(string $key, $default = null): mixed
    {
        return $this->delete[$key] ?? $default;
    }

    /**
     * Summary of head
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function head(string $key, $default = null): mixed
    {
        return $this->head[$key] ?? $default;
    }

    /**
     * Summary of options
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function options(string $key, $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Summary of patch
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function patch(string $key, $default = null): mixed
    {
        return $this->patch[$key] ?? $default;
    }

    /**
     * Summary of files
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function files(string $key, $default = null): mixed
    {
        return $this->files[$key] ?? $default;
    }

    /**
     * Summary of request
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function request(string $key, $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    /**
     * Summary of env
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function env(string $key, $default = null): mixed
    {
        return $this->env[$key] ?? $default;
    }

    /**
     * Summary of getGlobals
     * @return array
     */
    public function getGlobals(): array
    {
        return $GLOBALS;
        // Testing purposes
        return $this->globals;
    }

    /**
     * Summary of getGet
     * @return array
     */
    public function getGet(): array
    {
        return $this->get;
    }

    /**
     * Summary of getPost
     * @return array
     */
    public function getPost(): array
    {
        return $this->post;
    }

    /**
     * Summary of getServer
     * @return array
     */
    public function getServer(): array
    {
        return $this->server;
    }

    /**
     * Summary of getCookie
     * @return array
     */
    public function getCookie(): array
    {
        return $this->cookie;
    }

    /**
     * Summary of getPut
     * @return array
     */
    public function getPut(): array
    {
        return $this->put;
    }

    /**
     * Summary of getDelete
     * @return array
     */
    public function getDelete(): array
    {
        return $this->delete;
    }

    /**
     * Summary of getHead :3
     * @return array
     */
    public function getHead(): array
    {
        return $this->head;
    }

    /**
     * Summary of getOptions
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Summary of getPatch
     * @return array
     */
    public function getPatch(): array
    {
        return $this->patch;
    }

    /**
     * Summary of getFiles
     * @return array
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Summary of getRequest
     * @return array
     */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * Summary of getEnv
     * @return array
     */
    public function getEnv(): array
    {
        return $this->env;
    }
}
