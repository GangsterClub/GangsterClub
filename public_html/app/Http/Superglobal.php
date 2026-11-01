<?PHP

declare(strict_types=1);

namespace app\Http;

/**
 * Input-source adapter exposing one accessor per supported PHP superglobal.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class Superglobal
{
    //protected array $globals;
    protected array $get;
    protected array $post;
    protected array $server;
    protected array $cookie;
    protected array $put;
    protected array $delete;
    protected array $head;
    protected array $options;
    protected array $patch;
    protected array $files;
    protected array $request;
    protected array $env;

    public function __construct()
    {
        $server = $_SERVER;

        $server['REMOTE_ADDR_ORIGINAL'] = $server['REMOTE_ADDR'] ?? null;
        $server = $this->applyTrustedClientIp($server);

        // 515 = FILTER_SANITIZE_SPECIAL_CHARS
        //$this->globals = filter_var_array($GLOBALS, 515); // testing purposes
        $this->get = filter_input_array(INPUT_GET, 515) ?? [];
        $this->post = filter_input_array(INPUT_POST, 515) ?? [];
        $this->server = filter_var_array($server, 515) ?? [];
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

    private function applyTrustedClientIp(array $server): array
    {
        $clientIpHeader = getenv('CLIENT_IP_HEADER');
        $trustedProxies = getenv('CLIENT_IP_TRUSTED_PROXIES');
        $remoteAddress = $server['REMOTE_ADDR'] ?? null;

        if (is_string($clientIpHeader) === false || $clientIpHeader === '') {
            return $server;
        }
        if (is_string($trustedProxies) === false || is_string($remoteAddress) === false) {
            return $server;
        }
        if ($this->isTrustedProxy($remoteAddress, $trustedProxies) === false) {
            return $server;
        }

        $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $clientIpHeader));
        $clientIp = trim((string) ($server[$headerName] ?? ''));
        if (filter_var($clientIp, FILTER_VALIDATE_IP) !== false) {
            $server['REMOTE_ADDR'] = $clientIp;
        }

        return $server;
    }

    private function isTrustedProxy(string $ip, string $trustedProxies): bool
    {
        $ipBinary = @inet_pton($ip);
        if ($ipBinary === false) {
            return false;
        }

        foreach (explode(',', $trustedProxies) as $proxy) {
            [$subnet, $prefix] = array_pad(explode('/', trim($proxy), 2), 2, null);
            $subnetBinary = @inet_pton($subnet);

            if ($subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
                continue;
            }

            $bits = $prefix === null ? strlen($ipBinary) * 8 : filter_var($prefix, FILTER_VALIDATE_INT);

            if ($bits === false || $bits < 0 || $bits > strlen($ipBinary) * 8) {
                continue;
            }

            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if (substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
                continue;
            }

            if ($remainder === 0 || (
                (ord($ipBinary[$bytes]) >> (8 - $remainder)) ===
                (ord($subnetBinary[$bytes]) >> (8 - $remainder))
            )) {
                return true;
            }
        }

        return false;
    }

    protected function parseRequestMethod(string $method): array
    {
        if (REQUEST_METHOD === $method) {
            parse_str(file_get_contents('php://input'), $data);
            return filter_var_array($data, 515) ?? [];
        }

        return [];
    }

    public function globals(string $key, $default = null): mixed
    {
        // Testing purposes
        //$this->globals = filter_var_array($GLOBALS, 515);
        //return $this->globals[$key] ?? $default;
        $globals = filter_var_array($GLOBALS, 515);
        return $globals[$key] ?? $default;
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function server(string $key, $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function cookie(string $key, $default = null): mixed
    {
        return $this->cookie[$key] ?? $default;
    }

    public function put(string $key, $default = null): mixed
    {
        return $this->put[$key] ?? $default;
    }

    public function delete(string $key, $default = null): mixed
    {
        return $this->delete[$key] ?? $default;
    }

    public function head(string $key, $default = null): mixed
    {
        return $this->head[$key] ?? $default;
    }

    public function options(string $key, $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function patch(string $key, $default = null): mixed
    {
        return $this->patch[$key] ?? $default;
    }

    public function files(string $key, $default = null): mixed
    {
        return $this->files[$key] ?? $default;
    }

    public function request(string $key, $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    public function env(string $key, $default = null): mixed
    {
        return $this->env[$key] ?? $default;
    }

    public function getGlobals(): array
    {
        return $GLOBALS;
        // Testing purposes
        return $this->globals;
    }

    public function getGet(): array
    {
        return $this->get;
    }

    public function getPost(): array
    {
        return $this->post;
    }

    public function getServer(): array
    {
        return $this->server;
    }

    public function getCookie(): array
    {
        return $this->cookie;
    }

    public function getPut(): array
    {
        return $this->put;
    }

    public function getDelete(): array
    {
        return $this->delete;
    }

    public function getHead(): array
    {
        return $this->head;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getPatch(): array
    {
        return $this->patch;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getRequest(): array
    {
        return $this->request;
    }

    public function getEnv(): array
    {
        return $this->env;
    }
}
