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

        $jwtService = new JWTService($this->application);
        if ($session->get('UID') !== null) {
            $authorizationHeader = trim((string) ($request->server('HTTP_AUTHORIZATION') ?? ''));

            if ($authorizationHeader === '') {
                $storedToken = $session->get('jwt_token');
                if (is_string($storedToken) && $storedToken !== '') {
                    $authorizationHeader = 'Bearer ' . $storedToken;
                }
            }

            if ($authorizationHeader !== '') {
                $authorizationResult = $jwtService->authorize($authorizationHeader);

                if (is_array($authorizationResult) && isset($authorizationResult['token'])) {
                    $session->set('jwt_token', $authorizationResult['token']);
                }
            }
        }

        $session->remove('UID');
        $session->remove('UNAUTHENTICATED_UID');
        $session->remove('login.totp');
        $session->remove('TOTP_SECRET');
        $session->remove('jwt_token');
        $session->regenerate();
        $this->application->header('/login');
        return null;
    }
}
