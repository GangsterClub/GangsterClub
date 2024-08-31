<?PHP

declare(strict_types=1);

namespace app\Http;

class Response
{
    private string $content;
    private int $statusCode;
    private array $headers = [];

    public function __construct(string $content, int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public function send(): Response
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $header) {
            header($header);
        }
        print_r($this->content);
        return $this;
    }
}
