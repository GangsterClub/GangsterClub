<?PHP

declare(strict_types=1);

namespace app\Http;

class Request extends Superglobal
{
    /**
     * Summary of method
     * @var string
     */
    private string $method;
    private static string $requestMethod = 'HEAD';

    /**
     * Summary of parameters
     * @var array
     */
    private array $parameters;

    /**
     * Summary of headers
     * @var array
     */
    private array $headers;

    /**
     * Summary of __construct
     * @param string $method
     * @param array $headers
     * @param array $parameters
     */
    public function __construct(string $method, array $headers, ...$parameters)
    {
        parent::__construct();
        $this->method = $method;
        static::$requestMethod = $this->server('REQUEST_METHOD');
        $this->headers = $headers;
        $this->parameters = $parameters;
    }

    /**
     * Summary of capture
     * @return \app\Http\Request
     */
    public static function capture(): self
    {
        $headers = (getallheaders() ?? []);
        $method = (static::$requestMethod ?? 'GET');
        $parameters = Router::extract((REQUEST_URI ?? ''), $method);

        return new self((string) $method, (array) $headers, ...$parameters);
    }

    /**
     * Summary of getMethod
     * @return string
     */
    public function getMethod(): string
    {
        return (string) $this->method;
    }

    /**
     * Summary of getHeader
     * @param string $key
     * @return mixed
     */
    public function getHeader(string $key): mixed
    {
        return ($this->headers[$key] ?? null);
    }

    /**
     * Summary of getParameter
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getParameter(string $key, ?string $default = null): mixed
    {
        return ($this->parameters[$key] ?? $default);
    }
}
