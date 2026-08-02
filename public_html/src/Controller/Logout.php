<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use src\Business\RecoveryCodeService;

class Logout extends Controller
{
    public function __invoke(Request $request): Response
    {
        $auth = $this->auth();
        if ($request->getMethod() !== 'POST') {
            $routeName = $auth->getAuthenticatedUserId() === null ? 'login' : 'account';
            return Response::redirect(Router::path($routeName), 303);
        }

        $pendingSetId = $auth->getPendingRecoverySetId();
        $flowUserId = $auth->getAuthenticatedUserId() ?? $auth->getPendingUserId();
        $recoveryCodes = $this->application->get('recoveryCodeService');
        if ($pendingSetId !== null
            && $flowUserId !== null
            && $recoveryCodes instanceof RecoveryCodeService
        ) {
            $recoveryCodes->invalidatePendingSet(
                $flowUserId,
                $pendingSetId,
                'logout_abandonment'
            );
        }

        $auth->logoutUser();
        return Response::redirect(Router::path('login'), 303);
    }
}
