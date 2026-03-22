<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Service\SessionService;
use src\Business\AccountService;
use src\Business\EmailService;
use src\Business\MFATOTPService;
use src\Business\TOTPEmailService;
use src\Business\UserService;
use src\Entity\User;

class Account extends Controller
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,32}$/';
    private const EMAIL_CHANGE_TTL = 3600;
    private const MFA_SETUP_EMAIL_SESSION_KEY = 'account.mfa.email_totp_secret';

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
        $this->accountService = new AccountService($application);
        $this->userService = new UserService($application);
        $this->mfaService = new MFATOTPService($application);
    }

    public function __invoke(Request $request): string
    {
        $this->application->get('translationService')->setFile('account');
        $session = $this->application->get('sessionService');

        $this->enforceAuthentication($session);

        $userId = (int) $session->get('UID');
        $user = $this->userService->getUserById($userId);
        if ($user === null) {
            $this->application->header('/login');
        }

        $user = $this->handleUsernameChange($request, $user);
        $this->handleEmailChange($request, $user);
        $this->handleMfa($request, $user, $session);

        $pendingEmailChange = $this->formatPendingEmailChange($user->getId());
        $pendingSecret = $session->get('account.mfa.secret');
        $mfaEnabled = $this->mfaService->hasEnabledMfa($user->getId());
        $mfaLabel = APP_NAME . ':' . $user->getEmail();
        $otpauth = is_string($pendingSecret) && $pendingSecret !== ''
            ? $this->mfaService->generateProvisioningUri($pendingSecret, $mfaLabel)
            : null;
        $qrCodeUrl = is_string($pendingSecret) && $pendingSecret !== ''
            ? $this->mfaService->generateQRCode($pendingSecret, $mfaLabel)
            : null;

        return $this->twig->render(
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
        );
    }

    public function verifyEmailChange(Request $request): string
    {
        $this->application->get('translationService')->setFile('account');
        $token = (string) $request->getParameter('token', '');
        $status = AccountService::EMAIL_CHANGE_INVALID;
        if ($token !== '') {
            $status = $this->accountService->confirmEmailChange($token);
        }

        $messageKey = $this->getVerificationMessageKey($status);
        $isSuccess = $status === AccountService::EMAIL_CHANGE_CONFIRMED;

        return $this->twig->render(
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
        );
    }

    private function enforceAuthentication(SessionService $session): void
    {
        if ($session->get('UID') === null) {
            $this->application->header('/login');
        }
    }

    private function handleUsernameChange(Request $request, User $user): User
    {
        $submit = $request->post('submit_username');
        if ($submit === null) {
            return $user;
        }

        $username = trim((string) $request->post('username'));
        if ((bool) preg_match(self::USERNAME_PATTERN, $username) === false) {
            $this->accountMessages['errors'][] = __('account.username-invalid');
            return $user;
        }

        if (strcasecmp($username, $user->getUsername()) === 0) {
            $this->accountMessages['errors'][] = __('account.username-unchanged');
            return $user;
        }

        if ($this->accountService->isUsernameTaken($username, $user->getId()) === true) {
            $this->accountMessages['errors'][] = __('account.username-taken');
            return $user;
        }

        $updated = $this->accountService->changeUsername($user->getId(), $username);
        if ($updated === true) {
            $this->accountMessages['success'][] = __('account.username-updated');
            $refreshed = $this->userService->getUserById($user->getId());
            if ($refreshed instanceof User) {
                return $refreshed;
            }
        } else {
            $this->accountMessages['errors'][] = __('account.username-error');
        }

        return $user;
    }

    private function handleEmailChange(Request $request, User $user): void
    {
        $submit = $request->post('submit_email');
        if ($submit === null) {
            return;
        }

        $newEmail = trim((string) $request->post('new_email'));
        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->accountMessages['errors'][] = __('account.email-change-invalid');
            return;
        }

        if (strcasecmp($newEmail, $user->getEmail()) === 0) {
            $this->accountMessages['errors'][] = __('account.email-change-same');
            return;
        }

        if ($this->accountService->isEmailInUse($newEmail, $user->getId()) === true) {
            $this->accountMessages['errors'][] = __('account.email-change-conflict');
            return;
        }

        try {
            $rawToken = bin2hex(random_bytes(32));
        } catch (\Throwable $exception) {
            $this->accountMessages['errors'][] = __('account.email-change-error');
            return;
        }

        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', (time() + self::EMAIL_CHANGE_TTL));
        $created = $this->accountService->createEmailChangeRequest($user->getId(), $newEmail, $tokenHash, $expiresAt);
        if ($created === false) {
            $this->accountMessages['errors'][] = __('account.email-change-error');
            return;
        }

        $verificationUrl = WEB_ROOT . 'account/email/verify/' . $rawToken;
        $emailService = new EmailService();
        $emailSent = $emailService->sendEmailChangeVerification($user->getEmail(), $newEmail, $verificationUrl);
        if ($emailSent === false) {
            $this->accountService->deletePendingEmailChanges($user->getId());
            $this->accountMessages['errors'][] = __('account.email-change-error');
            return;
        }

        $this->accountMessages['success'][] = __('account.email-change-requested');
    }

    private function handleMfa(Request $request, User $user, SessionService $session): void
    {
        $userId = $user->getId();
        $isEnabled = $this->mfaService->hasEnabledMfa($userId);
        $pendingSecret = $session->get('account.mfa.secret');
        $hasPendingSetup = is_string($pendingSecret) && $pendingSecret !== '';

        if ($request->post('submit_mfa_setup') !== null) {
            if ($isEnabled === true) {
                $this->accountMessages['errors'][] = __('account.mfa-setup-already-enabled');
                return;
            }

            if ($hasPendingSetup === false) {
                $session->set('account.mfa.secret', $this->mfaService->generateSecret());
            }

            $totpEmailService = new TOTPEmailService($this->application);
            $emailCode = $totpEmailService->generateEmailTOTPForSession($userId, self::MFA_SETUP_EMAIL_SESSION_KEY);

            $emailService = new EmailService();
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
                $session->remove('account.mfa.secret');
                $session->remove(self::MFA_SETUP_EMAIL_SESSION_KEY);
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

            $totpEmailService = new TOTPEmailService($this->application);
            if ($totpEmailService->verifyEmailTOTPForSession($userId, $emailCode, self::MFA_SETUP_EMAIL_SESSION_KEY) === false) {
                $this->accountMessages['errors'][] = __('account.mfa-email-code-invalid');
                return;
            }

            $enabled = $this->mfaService->enableMfa($userId, $pendingSecret);
            if ($enabled === true) {
                $session->remove('account.mfa.secret');
                $session->remove(self::MFA_SETUP_EMAIL_SESSION_KEY);
                $this->accountMessages['success'][] = __('account.mfa-enabled');
            } else {
                $this->accountMessages['errors'][] = __('account.mfa-enable-error');
            }

            return;
        }

        if ($request->post('submit_mfa_disable') !== null) {
            if ($isEnabled === false) {
                if ($hasPendingSetup === true) {
                    $session->remove('account.mfa.secret');
                    $session->remove(self::MFA_SETUP_EMAIL_SESSION_KEY);
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
                $session->remove('account.mfa.secret');
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
