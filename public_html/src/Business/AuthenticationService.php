<?PHP

declare(strict_types=1);

namespace src\Business;

use app\Service\JWT;

class AuthenticationService
{
    public function authenticate($username, $hasValidCredentials = false) : string|false
    {
        if ($hasValidCredentials === false) {
            return false;
        }

        $jwt = new JWT();
        return $jwt->issue((string) $username);
    }
}
