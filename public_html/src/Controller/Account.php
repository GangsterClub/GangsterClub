<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use src\Business\AccountService;
use src\Business\EmailService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailTOTPService;
use src\Business\UserService;
use src\Entity\User;

class Account extends Controller
{
    private AccountService $accountService;

    private UserService $userService;

    private AuthenticatorTOTPService $authenticatorService;

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
        $pendingSecret = $auth->getPendingAuthenticatorSecret();
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

    private function getEmailTOTPService(): EmailTOTPService
    {
        $emailTotpService = $this->application->get('emailTotpService');
        if (($emailTotpService instanceof EmailTOTPService) === false) {
            throw new \RuntimeException('emailTotpService service is not available.');
        }

        return $emailTotpService;
    }

    private function handleAuthenticator(Request $request, User $user, AuthService $auth): void
    {
        $userId = $user->getId();
        $isEnabled = $this->authenticatorService->hasEnabledAuthenticator($userId);
        $pendingSecret = $auth->getPendingAuthenticatorSecret();
        $hasPendingSetup = is_string($pendingSecret) && $pendingSecret !== '';

        if ($request->post('submit_authenticator_setup') !== null) {
            $this->handleAuthenticatorSetup($user, $auth, $userId, $isEnabled, $hasPendingSetup);
            return;
        }

        if ($request->post('submit_authenticator_cancel') !== null) {
            $this->handleAuthenticatorCancel($auth, $isEnabled, $hasPendingSetup);
            return;
        }

        if ($request->post('submit_authenticator_confirm') !== null) {
            $this->handleAuthenticatorConfirm($request, $auth, $userId, $isEnabled, $pendingSecret, $hasPendingSetup);
            return;
        }

        if ($request->post('submit_authenticator_disable') !== null) {
            $this->handleAuthenticatorDisable($request, $auth, $userId, $isEnabled, $hasPendingSetup);
        }
    }

    private function handleAuthenticatorSetup(User $user, AuthService $auth, int $userId, bool $isEnabled, bool $hasPendingSetup): void
    {
        if ($isEnabled === true) {
            $this->accountMessages['errors'][] = __('account.authenticator-setup-already-enabled');
            return;
        }

        if ($hasPendingSetup === false) {
            $auth->setPendingAuthenticatorSecret($this->authenticatorService->generateSecret());
        }

        $emailTotpService = $this->getEmailTOTPService();
        $emailCode = $emailTotpService->generateEmailTOTPForSession($userId, $auth->getAuthenticatorSetupEmailSessionKey());

        $emailService = $this->application->get('emailService');
        if (($emailService instanceof EmailService) === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-email-code-send-error');
            return;
        }

        $emailSent = $emailService->sendEmailTOTP($user->getEmail(), $emailCode);
        if ($emailSent === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-email-code-send-error');
            return;
        }

        $this->accountMessages['success'][] = __('account.authenticator-secret-generated');
        $this->accountMessages['success'][] = __('account.authenticator-email-code-sent');
    }

    private function handleAuthenticatorCancel(AuthService $auth, bool $isEnabled, bool $hasPendingSetup): void
    {
        if ($isEnabled === false && $hasPendingSetup === true) {
            $auth->clearPendingAuthenticatorSetup();
            $this->accountMessages['success'][] = __('account.authenticator-secret-cleared');
            return;
        }

        if ($isEnabled === true) {
            $this->accountMessages['errors'][] = __('account.authenticator-disable-requires-verification');
            return;
        }

        $this->accountMessages['errors'][] = __('account.authenticator-requires-secret');
    }

    private function handleAuthenticatorConfirm(Request $request, AuthService $auth, int $userId, bool $isEnabled, ?string $pendingSecret, bool $hasPendingSetup): void
    {
        if ($isEnabled === true) {
            $this->accountMessages['errors'][] = __('account.authenticator-setup-already-enabled');
            return;
        }

        if ($hasPendingSetup === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-requires-secret');
            return;
        }

        $code = $this->normalizeOtpCode((string) $request->post('authenticator_code'));
        if ($this->isValidNumericCodeFormat($code, (int) AUTHENTICATOR_TOTP_DIGITS) === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-invalid-code');
            return;
        }

        $emailCode = $this->normalizeOtpCode((string) $request->post('authenticator_email_code'));
        if ($this->isValidNumericCodeFormat($emailCode, (int) TOTP_DIGITS) === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-email-code-required');
            return;
        }

        if ($this->authenticatorService->verifySecret($pendingSecret, $code) === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-invalid-code');
            return;
        }

        $emailTotpService = $this->getEmailTOTPService();
        if ($emailTotpService->verifyEmailTOTPForSession($userId, $emailCode, $auth->getAuthenticatorSetupEmailSessionKey()) === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-email-code-invalid');
            return;
        }

        $enabled = $this->authenticatorService->enableAuthenticator($userId, $pendingSecret);
        if ($enabled === true) {
            $auth->clearPendingAuthenticatorSetup();
            $auth->rotateCsrfToken();
            $this->accountMessages['success'][] = __('account.authenticator-enabled');
        } else {
            $this->accountMessages['errors'][] = __('account.authenticator-enable-error');
        }
    }

    private function handleAuthenticatorDisable(Request $request, AuthService $auth, int $userId, bool $isEnabled, bool $hasPendingSetup): void
    {
        if ($isEnabled === false) {
            if ($hasPendingSetup === true) {
                $auth->clearPendingAuthenticatorSetup();
                $this->accountMessages['success'][] = __('account.authenticator-secret-cleared');
            } else {
                $this->accountMessages['errors'][] = __('account.authenticator-disable-not-enabled');
            }

            return;
        }

        $code = $this->normalizeOtpCode((string) $request->post('authenticator_disable_code'));
        if ($this->isValidNumericCodeFormat($code, (int) AUTHENTICATOR_TOTP_DIGITS) === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-disable-code-required');
            return;
        }

        if ($this->authenticatorService->verifyEnabledSecret($userId, $code) === false) {
            $this->accountMessages['errors'][] = __('account.authenticator-disable-invalid-code');
            return;
        }

        $disabled = $this->authenticatorService->disableAuthenticator($userId);
        if ($disabled === true) {
            $auth->setPendingAuthenticatorSecret(null);
            $auth->rotateCsrfToken();
            $this->accountMessages['success'][] = __('account.authenticator-disabled');
        } else {
            $this->accountMessages['errors'][] = __('account.authenticator-disable-error');
        }
    }

    private function normalizeOtpCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }

    private function isValidNumericCodeFormat(string $code, int $digits): bool
    {
        $pattern = '/^\d{' . $digits . '}$/';

        return (bool) preg_match($pattern, $code);
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
