<?PHP

declare(strict_types=1);

namespace app\Service;

use app\Http\Request;

class SessionService extends \SessionHandler
{
    private ?string $ipAddress;
    private string $userAgent;

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $production = (bool) strtolower(ENVIRONMENT) === 'production';
        $development = (bool) DEVELOPMENT === true;
        $ipFilters = ($production === true && $development === false) ?
            (FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === true :
            (FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === true;

        $ipFilters |= FILTER_NULL_ON_FAILURE === true;
        $this->ipAddress = filter_var($request->server('REMOTE_ADDR'), FILTER_VALIDATE_IP, $ipFilters);
        $this->userAgent = (filter_var($request->server('HTTP_USER_AGENT'), 515) ?? 'Undefined');
    }

    public function start(string $name, ?int $limit = 0, ?string $path = '/', ?string $domain = null, ?bool $secure = null): void
    {
        ini_set('session.name', $name . '_Session');
        ini_set('session.auto_start', 'Off');
        ini_set('session.cookie_domain', $domain);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_lifetime', 1440);
        ini_set('session.cookie_secure', $secure);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.cache_expire', 30);
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 100);
        ini_set('session.gc_maxlifetime', 1440);
        session_name($name . '_Session');
        $https = isset($secure) === true ? $secure :
            $this->request->server('HTTPS') !== null;

        session_set_cookie_params($limit, $path, $domain, $https, true);
        session_start();
        if ($this->has('_IPaddress') === false) {
            $this->set('_IPaddress', ($this->ipAddress ?? '0.0.0.0'));
        }

        if ($this->has('_userAgent') === false) {
            $this->set('_userAgent', $this->userAgent);
        }

        if ($this->validate() === false) {
            $this->reset();
            session_destroy();
            session_start();
            $this->regenerate();
            return;
        }

        if ($this->preventHijacking() === false) {
            $this->reset();
            $this->regenerate();
        }
    }

    public function regenerate(): void
    {
        if ($this->has('_obsolete') === true && $this->get('_obsolete') === true) {
            return;
        }

        $this->set('_obsolete', true);
        $this->set('_expires', (time() + 10));
        session_regenerate_id(false);
        $newSession = session_id();
        session_write_close();
        session_id($newSession);
        session_start();
        $this->remove('_obsolete');
        $this->remove('_expires');
        $this->set('_lastNewSession', time());
    }

    protected function validate(): bool
    {
        if ($this->has('_lastNewSession') === false) {
            $this->set('_lastNewSession', time());
        }

        $obsolete = $this->get('_obsolete');
        $expires = $this->get('_expires');
        if (isset($obsolete) === true && isset($expires) === false) {
            return false;
        }

        if (isset($obsolete) === true && isset($expires) === true && $expires < time()) {
            return false;
        }

        return true;
    }

    protected function preventHijacking(): bool
    {
        $IPaddress = $this->get('_IPaddress');
        $uAgent = $this->get('_userAgent');
        if (isset($IPaddress) === false || isset($uAgent) === false) {
            return false;
        }

        $userAgent = $this->userAgent;
        if ($uAgent !== $userAgent) {
            return false;
        }

        if ($IPaddress !== $this->ipAddress) {
            return false;
        }

        return true;
    }

    public function writeClose(): void
    {
        $regenerate = ($this->get('_regenerate') ?? false);
        if (random_int(1, 100) <= 5 && $this->get('_lastNewSession') < (time() - 300) || ($regenerate === true)) {
            $this->remove('_regenerate');
            $this->regenerate();
        }

        session_write_close();
    }

    public function get(string $key, $default = null): mixed
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $default;
        }

        $session = $_SESSION ?? [];
        return isset($session[$key]) === true ?
            filter_var($session[$key], 515) :
            $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$key] = filter_var($value, 515);
        }
    }

    public function flash(string $bag, string $type, string $message): void
    {
        $messages = $this->getFlashMessages();
        $messages[$bag][$type][] = $message;
        $this->storeFlashMessages($messages);
    }

    public function consumeFlash(string $bag): array
    {
        $messages = $this->getFlashMessages();
        $bagMessages = $messages[$bag] ?? [];
        unset($messages[$bag]);
        $this->storeFlashMessages($messages);

        return [
            'errors' => is_array($bagMessages['errors'] ?? null) === true ? $bagMessages['errors'] : [],
            'success' => is_array($bagMessages['success'] ?? null) === true ? $bagMessages['success'] : [],
        ];
    }

    private function getFlashMessages(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $messages = $_SESSION['_flash_messages'] ?? [];
        return is_array($messages) === true ? $messages : [];
    }

    private function storeFlashMessages(array $messages): void
    {
        if ($messages === []) {
            $this->remove('_flash_messages');
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_flash_messages'] = $messages;
        }
    }

    public function has(string $key): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return $this->get($key) !== null;
        }

        return false;
    }

    public function remove(string $key): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[$key]);
        }
    }

    private function reset(): void
    {
        $_SESSION = [];
    }
}
