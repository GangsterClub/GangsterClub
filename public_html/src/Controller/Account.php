<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use src\Business\AccountService;
use src\Business\EmailService;
use src\Business\MFATOTPService;
use src\Business\TOTPEmailService;
use src\Business\UserService;
use src\Entity\User;

class Account extends Controller
{
    private AccountService $accountService;

    private UserService $userService;

    private MFATOTPService $mfaService;

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
        $mfaService = $application->get('mfaTotpService');
        if (($mfaService instanceof MFATOTPService) === false) {
            throw new \RuntimeException('mfaTotpService service is not available.');
        }

        $this->mfaService = $mfaService;
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
        $this->handleMfa($request, $user, $auth);

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
        $pendingSecret = $auth->getPendingMfaSecret();
        $mfaEnabled = $this->mfaService->hasEnabledMfa($user->getId());
        $mfaLabel = APP_NAME . ':' . $user->getEmail();
        $otpauth = (is_string($pendingSecret) === true && $pendingSecret !== '')
            ? $this->mfaService->generateProvisioningUri($pendingSecret, $mfaLabel)
            : null;
        $qrCodeUrl = (is_string($pendingSecret) === true && $pendingSecret !== '')
            ? $this->mfaService->generateQRCode($pendingSecret, $mfaLabel)
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
                        'mfa' => [
                            'enabled' => $mfaEnabled,
                            'pendingSecret' => $pendingSecret,
                            'otpauth' => $otpauth,
                            'qrCodeUrl' => $qrCodeUrl,
                            'digits' => (int) MFA_TOTP_DIGITS,
                            'period' => (int) MFA_TOTP_PERIOD,
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

    private function getTotpEmailService(): TOTPEmailService
    {
        $totpEmailService = $this->application->get('totpEmailService');
        if (($totpEmailService instanceof TOTPEmailService) === false) {
            throw new \RuntimeException('totpEmailService service is not available.');
        }

        return $totpEmailService;
    }

    private function handleMfa(Request $request, User $user, AuthService $auth): void
    {
        $userId = $user->getId();
        $isEnabled = $this->mfaService->hasEnabledMfa($userId);
        $pendingSecret = $auth->getPendingMfaSecret();
        $hasPendingSetup = is_string($pendingSecret) && $pendingSecret !== '';

        if ($request->post('submit_mfa_setup') !== null) {
            if ($isEnabled === true) {
                $this->accountMessages['errors'][] = __('account.mfa-setup-already-enabled');
                return;
            }

            if ($hasPendingSetup === false) {
                $auth->setPendingMfaSecret($this->mfaService->generateSecret());
            }

            $totpEmailService = $this->getTotpEmailService();
            $emailCode = $totpEmailService->generateEmailTOTPForSession($userId, $auth->getMfaSetupEmailSessionKey());

            $emailService = $this->application->get('emailService');
            if (($emailService instanceof EmailService) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-email-code-send-error');
                return;
            }

            $emailSent = $emailService->sendTOTPEmail($user->getEmail(), $emailCode);
            if ($emailSent === false) {
                $this->accountMessages['errors'][] = __('account.mfa-email-code-send-error');
                return;
            }

            $this->accountMessages['success'][] = __('account.mfa-secret-generated');
            $this->accountMessages['success'][] = __('account.mfa-email-code-sent');
            return;
        }

        if ($request->post('submit_mfa_cancel') !== null) {
            if ($isEnabled === false && $hasPendingSetup === true) {
                $auth->clearPendingMfaSetup();
                $this->accountMessages['success'][] = __('account.mfa-secret-cleared');
                return;
            }

            if ($isEnabled === true) {
                $this->accountMessages['errors'][] = __('account.mfa-disable-requires-verification');
                return;
            }

            $this->accountMessages['errors'][] = __('account.mfa-requires-secret');
            return;
        }

        if ($request->post('submit_mfa_confirm') !== null) {
            if ($isEnabled === true) {
                $this->accountMessages['errors'][] = __('account.mfa-setup-already-enabled');
                return;
            }

            if ($hasPendingSetup === false) {
                $this->accountMessages['errors'][] = __('account.mfa-requires-secret');
                return;
            }

            $code = $this->normalizeOtpCode((string) $request->post('mfa_code'));
            if ($this->isValidNumericCodeFormat($code, (int) MFA_TOTP_DIGITS) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-invalid-code');
                return;
            }

            $emailCode = $this->normalizeOtpCode((string) $request->post('mfa_email_code'));
            if ($this->isValidNumericCodeFormat($emailCode, (int) TOTP_DIGITS) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-email-code-required');
                return;
            }

            if ($this->mfaService->verifySecret($pendingSecret, $code) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-invalid-code');
                return;
            }

            $totpEmailService = $this->getTotpEmailService();
            if ($totpEmailService->verifyEmailTOTPForSession($userId, $emailCode, $auth->getMfaSetupEmailSessionKey()) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-email-code-invalid');
                return;
            }

            $enabled = $this->mfaService->enableMfa($userId, $pendingSecret);
            if ($enabled === true) {
                $auth->clearPendingMfaSetup();
                $auth->rotateCsrfToken();
                $this->accountMessages['success'][] = __('account.mfa-enabled');
            } else {
                $this->accountMessages['errors'][] = __('account.mfa-enable-error');
            }

            return;
        }

        if ($request->post('submit_mfa_disable') !== null) {
            if ($isEnabled === false) {
                if ($hasPendingSetup === true) {
                    $auth->clearPendingMfaSetup();
                    $this->accountMessages['success'][] = __('account.mfa-secret-cleared');
                } else {
                    $this->accountMessages['errors'][] = __('account.mfa-disable-not-enabled');
                }

                return;
            }

            $code = $this->normalizeOtpCode((string) $request->post('mfa_disable_code'));
            if ($this->isValidNumericCodeFormat($code, (int) MFA_TOTP_DIGITS) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-disable-code-required');
                return;
            }

            if ($this->mfaService->verifyEnabledSecret($userId, $code) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-disable-invalid-code');
                return;
            }

            $disabled = $this->mfaService->disableMfa($userId);
            if ($disabled === true) {
                $auth->setPendingMfaSecret(null);
                $auth->rotateCsrfToken();
                $this->accountMessages['success'][] = __('account.mfa-disabled');
            } else {
                $this->accountMessages['errors'][] = __('account.mfa-disable-error');
            }
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
