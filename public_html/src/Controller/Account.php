<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;
use src\Business\AuthEntryService;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;
use src\Identity\AccountSecurity\Http\AccountSecurityFormHandler;
use src\Identity\Profile\Http\EmailChangeVerification;
use src\Identity\Profile\Http\ProfileFormHandler;

class Account extends Controller
{
    private ProfileFormHandler $profileFormHandler;

    private EmailChangeVerification $emailChangeVerification;

    private AccountSecurityFormHandler $accountSecurityFormHandler;

    private UserService $userService;

    private AuthenticatorTOTPService $authenticatorService;

    private RecoveryFeatureService $recoveryFeatureService;

    private array $accountMessages = [
        'errors' => [],
        'success' => [],
    ];

    public function __construct(Application $application)
    {
        parent::__construct($application);
        $this->profileFormHandler = $application->get(ProfileFormHandler::class);
        $this->emailChangeVerification = $application->get(EmailChangeVerification::class);
        $this->accountSecurityFormHandler = $application->get(AccountSecurityFormHandler::class);
        $this->userService = $application->get(UserService::class);
        $this->authenticatorService = $application->get(AuthenticatorTOTPService::class);
        $this->recoveryFeatureService = $application->get(RecoveryFeatureService::class);
    }

    public function __invoke(Request $request): Response
    {
        $this->application->get(\app\Service\TranslationService::class)->setFile('account');
        $auth = $this->auth();

        $redirect = $this->enforceAuthentication($request, $auth);
        if ($redirect instanceof Response) {
            return $redirect;
        }

        $userId = $auth->getAuthenticatedUserId();
        if ($userId === null) {
            return $this->redirectToLogin($request);
        }

        $user = $this->userService->getUserById($userId);
        if ($user === null) {
            return $this->redirectToLogin($request);
        }

        $this->accountMessages = $this->consumeFlash('account');

        $profileResult = $this->profileFormHandler->handle($request, $user);
        $user = $profileResult['user'];
        $this->accountMessages['errors'] = array_merge(
            $this->accountMessages['errors'],
            $profileResult['errors']
        );
        $this->accountMessages['success'] = array_merge(
            $this->accountMessages['success'],
            $profileResult['success']
        );
        $securityMessages = $this->accountSecurityFormHandler->handle($request, $user);
        $this->accountMessages['errors'] = array_merge(
            $this->accountMessages['errors'],
            $securityMessages['errors']
        );
        $this->accountMessages['success'] = array_merge(
            $this->accountMessages['success'],
            $securityMessages['success']
        );

        if ($request->getMethod() === 'POST') {
            foreach ($this->accountMessages['errors'] as $message) {
                $this->flash('account', 'errors', (string) $message);
            }

            foreach ($this->accountMessages['success'] as $message) {
                $this->flash('account', 'success', (string) $message);
            }

            return $this->redirectSelf();
        }

        $pendingEmailChange = $this->profileFormHandler->pendingEmailChange($user->getId());
        $authenticatorEnabled = $this->authenticatorService->hasEnabledAuthenticator($user->getId());

        return Response::html(
            $this->twig->render(
                'account.twig',
                array_merge(
                    $this->twigVariables,
                    [
                        'user' => $user,
                        'account' => $this->accountMessages,
                        'usernamePattern' => str_replace('_-', '_\-', AuthEntryService::REGISTRATION_USERNAME_PATTERN),
                        'pendingEmailChange' => $pendingEmailChange,
                        'authenticator' => [
                            'enabled' => $authenticatorEnabled,
                            'digits' => (int) AUTHENTICATOR_TOTP_DIGITS,
                            'period' => (int) AUTHENTICATOR_TOTP_PERIOD,
                            'emailDigits' => (int) TOTP_DIGITS,
                            'emailPeriod' => (int) TOTP_PERIOD,
                            'twoStepRequired' => $user->isTwoStepVerificationRequired(),
                        ],
                        'recoveryCodes' => [
                            'active' => $this->recoveryFeatureService->getActiveRecoverySetId($user->getId()) !== null,
                            'remainingCount' => $this->recoveryFeatureService->getUnusedRecoveryCodeCount($user->getId()),
                        ],
                    ]
                )
            )
        );
    }

    public function verifyEmailChange(Request $request): Response
    {
        $this->application->get(\app\Service\TranslationService::class)->setFile('account');
        return $this->emailChangeVerification->verify($request);
    }

    private function enforceAuthentication(Request $request, AuthService $auth): ?Response
    {
        if ($auth->getAuthenticatedUserId() === null) {
            return $this->redirectToLogin($request);
        }

        return null;
    }

    private function redirectToLogin(Request $request): Response
    {
        $statusCode = strtoupper($request->getMethod()) === 'POST' ? 303 : 302;

        return Response::redirect(Router::path('login'), $statusCode);
    }

}
