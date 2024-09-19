<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;

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
        $session->remove('UID');
        $session->remove('UNAUTHENTICATED_UID');
        $session->remove('login.totp');
        $session->remove('TOTP_SECRET');
        $session->regenerate();
        $this->application->header('/login');
    }
}
