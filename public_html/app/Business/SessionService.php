<?PHP

declare(strict_types=1);

namespace app\Business;

class SessionService extends \SessionHandler
{
    private ?string $ipAddress;
    private string $userAgent;

    public function __construct()
    {
        $production = (bool) (defined('ENVIRONMENT') === true && strtolower(ENVIRONMENT) === 'production');
        $development = (bool) (defined('DEVELOPMENT') === true && DEVELOPMENT === true);
        $ipFilters = ($production === true && $development === false) ?
            (FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === true :
            (FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === true;

        $ipFilters |= FILTER_NULL_ON_FAILURE;
        $this->ipAddress = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP, $ipFilters);
        $this->userAgent = (filter_input(INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? 'Undefined');
    }

    public function start(string $name, ?int $limit=0, ?string $path='/', ?string $domain=null, ?bool $secure=null): void
    {
        ini_set('session.name', $name.'_Session');
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
        session_name($name.'_Session');
        $https = isset($secure) === true ? $secure :
            (filter_input(INPUT_SERVER, 'HTTPS', FILTER_SANITIZE_FULL_SPECIAL_CHARS)) !== null;

        session_set_cookie_params($limit, $path, $domain, $https, true);
        session_start();
        if ($this->validate() === false) {
            $this->reset();
            session_destroy();
            session_start();
            $this->regenerate();
            return;
        }

        if ($this->preventHijacking() === false) {
            $this->reset();
            $this->set('_IPaddress', ($this->ipAddress ?? '0.0.0.0'));
            $this->set('_userAgent', $this->userAgent);
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

        $remoteIpHeader = $this->ipAddress;
        if ($IPaddress !== $remoteIpHeader) {
            return false;
        }

        return true;
    }

    public function writeClose(): void
    {
        $regenerate = ($this->get('_regenerate') ?? false);
        if (random_int(1, 100) <= 5 && $this->get('_lastNewSession') < (time() - 300) || ($regenerate === true)) {
            $this->set('_regenerate', null);
            $this->regenerate();
        }

        session_write_close();
    }

    public function get(string $key, $default=null): mixed
    {
        return isset($_SESSION[$key]) ?
            filter_var($_SESSION[$key], FILTER_SANITIZE_FULL_SPECIAL_CHARS) :
            $default;
    }

    public function set(string $key, $value): void
    {
        $_SESSION[$key] = filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    private function reset(): void
    {
        $_SESSION = [];
    }
}
