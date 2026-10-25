<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use src\Business\RecoveryCodeService;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;

class Logout extends Controller
{
    public function __invoke(Request $request): Response
    {
        $auth = $this->auth();
        if ($request->getMethod() !== 'POST') {
            $routeName = $auth->getAuthenticatedUserId() === null ? 'login' : 'account';
            return Response::redirect(Router::path($routeName), 303);
        }

        $session = $this->application->get(\app\Service\SessionService::class);
        $securityFlow = $this->application->getOptional(SecurityFlowSession::class)
            ?? new SecurityFlowSession($session);
        $pendingAuthentication = $this->application->getOptional(PendingAuthenticationSession::class)
            ?? new PendingAuthenticationSession($session);
        $pendingSetId = $securityFlow->getPendingRecoverySetId();
        $flowUserId = $auth->getAuthenticatedUserId() ?? $pendingAuthentication->getUserId();
        $recoveryCodes = $this->application->get(RecoveryCodeService::class);
        if ($pendingSetId !== null
            && $flowUserId !== null
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
