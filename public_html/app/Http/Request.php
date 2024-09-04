<?PHP

declare(strict_types=1);

namespace app\Http;

class Request
{
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
     * Summary of method
     * @var string
     */
    private string $method;

    /**
     * Summary of __construct
     * @param array $parameters
     * @param array $headers
     * @param string $method
     */
    public function __construct(array $parameters, array $headers, string $method)
    {
        $this->parameters = $parameters;
        $this->headers = $headers;
        $this->method = $method;
    }

    /**
     * Summary of capture
     * @return \app\Http\Request
     */
    public static function capture(): self
    {
        $headers = (getallheaders() ?? []);
        $method = (filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'GET');
        $parameters = Router::extract((REQUEST_URI ?? ''), $method);

        return new self((array) $parameters, (array) $headers, (string) $method);
    }

    /**
     * Summary of getParameter
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getParameter(string $key, ?string $default=null): mixed
    {
        return ($this->parameters[$key] ?? $default);
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
     * Summary of getMethod
     * @return string
     */
    public function getMethod(): string
    {
        return (string) $this->method;
    }
}
