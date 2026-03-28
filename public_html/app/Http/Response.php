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
        $this->content = (string) $content;
        $this->statusCode = (int) $statusCode;
        $this->headers = (array) $headers;
    }

    public static function html(string $content, int $statusCode = 200, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }

    public static function json(array $payload, int $statusCode = 200, array $headers = []): self
    {
        $encoded = json_encode($payload);
        if ($encoded === false) {
            $encoded = '{}';
        }

        $headers[] = 'Content-Type: application/json; charset=utf-8';
        return new self($encoded, $statusCode, $headers);
    }

    public static function redirect(string $location, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location: ' . $location]);
    }

    public function withHeader(string $header): self
    {
        $response = clone $this;
        $response->headers[] = $header;
        return $response;
    }

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
