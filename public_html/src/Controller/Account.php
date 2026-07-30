<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use src\Business\AccountService;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;
use src\Entity\User;

class Account extends Controller
{
    private AccountService $accountService;

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
        $accountService = $application->get('accountService');
        if (($accountService instanceof AccountService) === false) {
            throw new \RuntimeException('accountService service is not available.');
        }

        $this->accountService = $accountService;
        $userService = $application->get('userService');
        if (($userService instanceof UserService) === false) {
            throw new \RuntimeException('userService service is not available.');
        }

        $this->userService = $userService;
        $authenticatorService = $application->get('authenticatorTotpService');
        if (($authenticatorService instanceof AuthenticatorTOTPService) === false) {
            throw new \RuntimeException('authenticatorTotpService service is not available.');
        }

        $this->authenticatorService = $authenticatorService;
        $recoveryFeatureService = $application->get('recoveryFeatureService');
        if (($recoveryFeatureService instanceof RecoveryFeatureService) === false) {
            throw new \RuntimeException('recoveryFeatureService service is not available.');
        }
        $this->recoveryFeatureService = $recoveryFeatureService;
    }

    public function __invoke(Request $request): Response
    {
        $this->application->get('translationService')->setFile('account');
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

        $user = $this->handleUsernameChange($request, $user);
        $this->handleEmailChange($request, $user);
        $this->handleAuthenticator($request, $user, $auth);

        if ($request->getMethod() === 'POST') {
            foreach ($this->accountMessages['errors'] as $message) {
                $this->flash('account', 'errors', (string) $message);
            }

            foreach ($this->accountMessages['success'] as $message) {
                $this->flash('account', 'success', (string) $message);
            }

            return $this->redirectSelf();
        }

        $pendingEmailChange = $this->formatPendingEmailChange($user->getId());
        $pendingSecret = null;
        $authenticatorEnabled = $this->authenticatorService->hasEnabledAuthenticator($user->getId());
        $authenticatorLabel = APP_NAME . ':' . $user->getEmail();
        $otpauth = (is_string($pendingSecret) === true && $pendingSecret !== '')
            ? $this->authenticatorService->generateProvisioningUri($pendingSecret, $authenticatorLabel)
            : null;
        $qrCodeUrl = (is_string($pendingSecret) === true && $pendingSecret !== '')
            ? $this->authenticatorService->generateQRCode($pendingSecret, $authenticatorLabel)
            : null;

        return Response::html(
            $this->twig->render(
                'account.twig',
                array_merge(
                    $this->twigVariables,
                    [
                        'user' => $user,
                        'account' => $this->accountMessages,
                        'pendingEmailChange' => $pendingEmailChange,
                        'authenticator' => [
                            'enabled' => $authenticatorEnabled,
                            'pendingSecret' => $pendingSecret,
                            'otpauth' => $otpauth,
                            'qrCodeUrl' => $qrCodeUrl,
                            'digits' => (int) AUTHENTICATOR_TOTP_DIGITS,
                            'period' => (int) AUTHENTICATOR_TOTP_PERIOD,
                            'emailDigits' => (int) TOTP_DIGITS,
                            'emailPeriod' => (int) TOTP_PERIOD,
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
        $this->application->get('translationService')->setFile('account');
        $token = (string) $request->getParameter('token', '');
        $status = AccountService::EMAIL_CHANGE_INVALID;
        if ($token !== '') {
            $status = $this->accountService->confirmEmailChange($token);
        }

        $messageKey = $this->getVerificationMessageKey($status);
        $isSuccess = $status === AccountService::EMAIL_CHANGE_CONFIRMED;

        return Response::html(
            $this->twig->render(
                'account-email-verify.twig',
                array_merge(
                    $this->twigVariables,
                    [
                        'verification' => [
                            'success' => $isSuccess,
                            'message' => __($messageKey),
                        ],
                    ]
                )
            )
        );
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

        return Response::redirect(APP_BASE . '/login', $statusCode);
    }

    private function handleUsernameChange(Request $request, User $user): User
    {
        $submit = $request->post('submit_username');
        if ($submit === null) {
            return $user;
        }

        $username = (string) $request->post('username');
        $status = $this->accountService->changeUsername($user, $username);

        if ($status === AccountService::USERNAME_CHANGE_UPDATED) {
            $this->accountMessages['success'][] = __('account.username-updated');
            $refreshed = $this->userService->getUserById($user->getId());
            if ($refreshed instanceof User) {
                return $refreshed;
            }

            return $user;
        }

        $messageKeys = [
            AccountService::USERNAME_CHANGE_INVALID => 'account.username-invalid',
            AccountService::USERNAME_CHANGE_UNCHANGED => 'account.username-unchanged',
            AccountService::USERNAME_CHANGE_TAKEN => 'account.username-taken',
            AccountService::USERNAME_CHANGE_ERROR => 'account.username-error',
        ];
        $this->accountMessages['errors'][] = __($messageKeys[$status] ?? 'account.username-error');

        return $user;
    }

    private function handleEmailChange(Request $request, User $user): void
    {
        $submit = $request->post('submit_email');
        if ($submit === null) {
            return;
        }

        $newEmail = (string) $request->post('new_email');
        $status = $this->accountService->requestEmailChange($user, $newEmail);

        if ($status === AccountService::EMAIL_CHANGE_REQUESTED) {
            $this->accountMessages['success'][] = __('account.email-change-requested');
            return;
        }

        $messageKeys = [
            AccountService::EMAIL_CHANGE_INVALID => 'account.email-change-invalid',
            AccountService::EMAIL_CHANGE_SAME => 'account.email-change-same',
            AccountService::EMAIL_CHANGE_CONFLICT => 'account.email-change-conflict',
            AccountService::EMAIL_CHANGE_ERROR => 'account.email-change-error',
        ];
        $this->accountMessages['errors'][] = __($messageKeys[$status] ?? 'account.email-change-error');
    }

    private function handleAuthenticator(Request $request, User $user, AuthService $auth): void
    {
        if ($request->post('submit_authenticator_setup') !== null
            || $request->post('submit_authenticator_cancel') !== null
            || $request->post('submit_authenticator_confirm') !== null
        ) {
            $this->accountMessages['errors'][] = __('account.authenticator-flow-required');
            return;
        }
    }

    private function formatPendingEmailChange(int $userId): ?array
    {
        $pending = $this->accountService->getPendingEmailChange($userId);
        if ($pending === null) {
            return null;
        }

        try {
            $expiresAt = new \DateTimeImmutable($pending->expires_at);
        } catch (\Exception) {
            $expiresAt = null;
        }

        return [
            'newEmail' => $pending->new_email,
            'expiresAt' => $expiresAt,
        ];
    }

    private function getVerificationMessageKey(string $status): string
    {
        return match ($status) {
            AccountService::EMAIL_CHANGE_CONFIRMED => 'account.email-change-confirmed',
            AccountService::EMAIL_CHANGE_EXPIRED => 'account.email-change-expired',
            AccountService::EMAIL_CHANGE_CONFLICT => 'account.email-change-conflict-token',
            default => 'account.email-change-invalid-token',
        };
    }
}
