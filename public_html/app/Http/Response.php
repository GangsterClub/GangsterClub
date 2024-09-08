<?PHP

declare(strict_types=1);

namespace app\Http;

class Response
{
    /**
     * Summary of content
     * @var string
     */
    private string $content;

    /**
     * Summary of statusCode
     * @var int
     */
    private int $statusCode;

    /**
     * Summary of headers
     * @var array
     */
    private array $headers = [];

    /**
     * Summary of __construct
     * @param string $content
     * @param int $statusCode
     * @param array $headers
     */
    public function __construct(string $content, int $statusCode = 200, array $headers = [])
    {
        $this->content = (string) $content;
        $this->statusCode = (int) $statusCode;
        $this->headers = (array) $headers;
    }

    /**
     * Summary of send
     * @return \app\Http\Response
     */
    public function send(): Response
    {
        if (headers_sent() === false) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $header) {
                header($header);
            }
        }

        print_r($this->content);
        return $this;
    }
}
