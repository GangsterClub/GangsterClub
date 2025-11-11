<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Service\JWTService;

class Authenticate extends Controller
{
    public function authenticate($username, $hasValidCredentials = false) : string|false
    {
        $jwtService = new JWTService($this->application);
        return $jwtService->authenticate((string) $username, (bool) $hasValidCredentials);
    }
}
