<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\FlashBag;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use src\Business\AuthEntryService;
use src\Business\RecoveryFeatureService;
use src\Controller\AuthEntry\AuthEntryRequestHandler;
use src\Controller\AuthEntry\AuthEntryResultResponder;
use src\Controller\AuthEntry\AuthEntryViewModelFactory;
use src\Controller\AuthEntry\RegistrationFormState;

class AuthEntryController extends Controller
{
    private const MODE_LOGIN = 'login';
    private const MODE_REGISTER = 'register';
    private const LOGIN_RECOVERY_FLASH_BAG = 'login.recovery';

    public function handle(Request $request, string $mode): Response
    {
        $this->assertValidMode($mode);
        $this->application->get(\app\Service\TranslationService::class)->setFile($mode);
        $auth = $this->auth();
        if ($auth->getAuthenticatedUserId() !== null) {
            return Response::redirect(Router::path('account'), 303);
        }

        $flash = $this->flashBag();
        $this->twigVariables[$mode] = $flash->consume($mode);
        $this->twigVariables['loginRecovery'] = $mode === self::MODE_LOGIN
            ? $flash->consume(self::LOGIN_RECOVERY_FLASH_BAG)
            : ['errors' => [], 'success' => []];

        $handler = $this->requestHandler($auth);
        $responder = new AuthEntryResultResponder($flash);
        $firstStep = $handler->firstStep($request, $mode);
        if ($firstStep !== null) {
            return $responder->firstStep($mode, $firstStep);
        }
        $verification = $handler->verification($request, $mode);
        if ($verification !== null) {
            return $responder->verification($mode, $verification);
        }

        return Response::html($this->twig->render(
            $mode . '.twig',
            array_merge($this->twigVariables, $this->viewModelFactory()->build($auth, $mode))
        ));
    }

    private function assertValidMode(string $mode): void
    {
        if (in_array($mode, [self::MODE_LOGIN, self::MODE_REGISTER], true) === false) {
            throw new \InvalidArgumentException('Unsupported auth entry mode: ' . $mode);
        }
    }

    protected function authEntryService(): AuthEntryService
    {
        return $this->application->get(AuthEntryService::class);
    }

    private function requestHandler(AuthService $auth): AuthEntryRequestHandler
    {
        $session = $this->application->get(SessionService::class);
        return new AuthEntryRequestHandler(
            $this->authEntryService(),
            $auth,
            $this->pendingAuthentication(),
            $this->securityFlow(),
            $session,
            $this->application->get(RecoveryFeatureService::class),
            new RegistrationFormState($session)
        );
    }

    private function viewModelFactory(): AuthEntryViewModelFactory
    {
        $session = $this->application->get(SessionService::class);
        return new AuthEntryViewModelFactory(
            $this->pendingAuthentication(),
            $this->application->get(RecoveryFeatureService::class),
            new RegistrationFormState($session)
        );
    }

    private function flashBag(): FlashBag
    {
        $session = $this->application->get(SessionService::class);
        $flash = $this->application->getOptional(FlashBag::class);
        return $flash instanceof FlashBag ? $flash : new FlashBag($session);
    }

    private function pendingAuthentication(): PendingAuthenticationSession
    {
        $session = $this->application->get(SessionService::class);
        $pending = $this->application->getOptional(PendingAuthenticationSession::class);
        return $pending instanceof PendingAuthenticationSession
            ? $pending
            : new PendingAuthenticationSession($session);
    }

    private function securityFlow(): SecurityFlowSession
    {
        $session = $this->application->get(SessionService::class);
        $flow = $this->application->getOptional(SecurityFlowSession::class);
        return $flow instanceof SecurityFlowSession ? $flow : new SecurityFlowSession($session);
    }
}
