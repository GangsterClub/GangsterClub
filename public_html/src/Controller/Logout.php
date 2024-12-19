<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Service\JWTService;

class Logout extends Controller
{
    /**
     * Summary of __invoke
     * @param \app\Http\Request $request
     * @return void
     */
    public function __invoke(Request $request): ?string
    {
        $session = $this->application->get('sessionService');
    
        // The logout page on the other hand is partially a protected resource!
        $jwtService = new JWTService($this->application);
        if ($session->get('UID') !== null)
            $jwtService->authorize($request->server('HTTP_AUTHORIZATION'));

        $session->remove('UID');
        $session->remove('UNAUTHENTICATED_UID');
        $session->remove('login.totp');
        $session->remove('TOTP_SECRET');
        $session->remove('jwt_token');
        $session->regenerate();
        $this->application->header('/login');
    }
}
