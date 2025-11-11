<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Response;
use app\Service\JWTService;

class HandleJWT extends Controller
{
    /**
     * Summary of authenticate
     * @param string $username
     * @param bool $hasValidCredentials
     * @return string|false
     */
    public function authenticate(string $username, bool $hasValidCredentials = false) : string|false
    {
        $jwtService = new JWTService($this->application);
        return $jwtService->authenticate($username, $hasValidCredentials);
    }

    /**
     * Summary of authorize
     * @param string $authorization
     * @return \app\Http\Response|array
     */
    public function authorize(string $authorization) : Response|array
    {
        $jwtService = new JWTService($this->application);
        return $jwtService->authorize($authorization);
    }
}
