<?PHP

declare(strict_types=1);

namespace app\Business;

class SessionService extends \SessionHandler
{
    public function start(string $name, ?int $limit = 0, ?string $path = '/', ?string $domain = null, ?bool $secure = null) : void
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
        $https = isset($secure) ? $secure : isset($_SERVER['HTTPS']);
        session_set_cookie_params($limit, $path, $domain, $https, true);
        session_start();
        if ($this->validate()) {
            if (!$this->preventHijacking()) {
                $_SESSION = array();
                $_SESSION['_IPaddress'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['_userAgent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Undefined';
                $this->regenerate();
            } elseif (isset($_SESSION['_regenerate']) && $_SESSION['_regenerate'] === true) {
                $_SESSION['_regenerate'] = null;
                $this->regenerate();
            } elseif (random_int(1, 100) <= 5 && $_SESSION['_lastNewSession'] < (time() - 300)) {
                $this->regenerate();
            }
            return;
        }
        $_SESSION = array();
        session_destroy();
        session_start();
        $this->regenerateSession();
    }

    public function regenerate() : void
    {
        if (isset($_SESSION['_obsolete']) && $_SESSION['_obsolete'] === true)
            return;

        $_SESSION['_obsolete'] = true;
        $_SESSION['_expires'] = time() + 10;
        session_regenerate_id(false);
        $newSession = session_id();
        session_write_close();
        session_id($newSession);
        session_start();
        unset($_SESSION['_obsolete']);
        unset($_SESSION['_expires']);
        $_SESSION['_lastNewSession'] = time();
    }

    protected function validate() : bool
    {
        if (!isset($_SESSION['_lastNewSession']))
            $_SESSION['_lastNewSession'] = time();

        if (isset($_SESSION['_obsolete']) && !isset($_SESSION['_expires']))
            return false;

        if (isset($_SESSION['_obsolete']) && isset($_SESSION['_expires']) && $_SESSION['_expires'] < time())
            return false;

        return true;
    }

    protected function preventHijacking() : bool
    {
        if (!isset($_SESSION['_IPaddress']) || !isset($_SESSION['_userAgent']))
            return false;

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Undefined';

        if ($_SESSION['_userAgent'] !== $userAgent)
            return false;

        $remoteIpHeader = $_SERVER['REMOTE_ADDR'];
        if ($_SESSION['_IPaddress'] !== $remoteIpHeader)
            return false;

        return true;
    }

    public function writeClose() : void
    {
        session_write_close();
    }
}
