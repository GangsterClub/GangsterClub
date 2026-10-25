<?PHP

declare(strict_types=1);

namespace src\Business;

use app\Service\AuthService;
use app\Service\PendingAuthenticationSession;
use app\Service\SessionService;
use src\Entity\User;

class AuthEntryService
{
    public const MODE_LOGIN = 'login';
    public const MODE_REGISTER = 'register';
    public const STATUS_EMAIL_OTP_SENT = 'email_otp_sent';
    public const STATUS_AUTHENTICATOR_CODE_REQUIRED = 'authenticator_code_required';
    public const STATUS_VALIDATION_ERROR = 'validation_error';
    public const STATUS_SEND_ERROR = 'send_error';
    public const STATUS_INVALID_OTP = 'invalid_otp';
    public const STATUS_INVALID_RECOVERY_CODE = 'invalid_recovery_code';
    public const STATUS_RECOVERY_CODE_UNAVAILABLE = 'recovery_code_unavailable';
    public const STATUS_AUTHENTICATED = 'authenticated';
    public const REGISTRATION_USERNAME_PATTERN = '[A-Za-z0-9._-]{3,32}';

    private const PENDING_REGISTRATION_USERNAME = 'PENDING_REGISTRATION_USERNAME';
    private const PENDING_CREATE_BY_EMAIL = 'PENDING_CREATE_BY_EMAIL';
    public function __construct(
        private readonly UserService $userService,
        private readonly AuthenticatorTOTPService $authenticatorService,
        private readonly EmailTOTPService $emailTotpService,
        private readonly TOTPService $totpService,
        private readonly EmailService $emailService,
        private readonly SessionService $sessionService,
        private readonly ?RecoveryCodeService $recoveryCodeService = null,
        ?PendingAuthenticationSession $pendingAuthentication = null,
        ?AuthEntryOtpService $otp = null
    ) {
        $this->pendingAuthentication = $pendingAuthentication ?? new PendingAuthenticationSession($sessionService);
        $this->otp = $otp ?? new AuthEntryOtpService(
            $emailTotpService,
            $totpService,
            $emailService,
            $sessionService
        );
    }

    private readonly PendingAuthenticationSession $pendingAuthentication;
    private readonly AuthEntryOtpService $otp;

    public function beginLogin(AuthService $auth, string $email): array
    {
        $email = trim($email);
        $user = $this->userService->getUserByEmail($email);

        $this->pendingAuthentication->setEmail($email);
        $this->pendingAuthentication->setTotp(null);
        $this->pendingAuthentication->setAuthenticatorRequired(false);
        $this->pendingAuthentication->setTwoStepRequired(false);

        if ($user !== null) {
            $userId = (int) $user->getId();
            $this->pendingAuthentication->setUserId($userId);
            $this->sessionService->remove(self::PENDING_CREATE_BY_EMAIL);

            if ($this->authenticatorService->hasEnabledAuthenticator($userId) === true) {
                if ($user->isTwoStepVerificationRequired() === true) {
                    $this->pendingAuthentication->setTwoStepRequired(true);
                    return $this->otp->issuePersisted($userId, $email, $this->rateLimitContext($userId));
                }
                $this->pendingAuthentication->setAuthenticatorRequired(true);
                return ['status' => self::STATUS_AUTHENTICATOR_CODE_REQUIRED];
            }

            return $this->otp->issuePersisted($userId, $email, $this->rateLimitContext($userId));
        }

        $this->pendingAuthentication->setUserId(null);
        $this->sessionService->set(self::PENDING_CREATE_BY_EMAIL, true);
        return $this->otp->issueEmailOnly($email);
    }

    public function beginRegistration(AuthService $auth, string $username, string $email): array
    {
        $username = trim($username);
        $email = trim($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'provide-valid-email'];
        }

        if ($username === '' || preg_match('/^' . self::REGISTRATION_USERNAME_PATTERN . '$/', $username) !== 1) {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'provide-valid-username'];
        }

        if ($this->userService->getUserByEmail($email) !== null) {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'duplicate-email'];
        }

        if ($this->userService->getUserByUsername($username) !== null) {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'duplicate-username'];
        }

        $this->pendingAuthentication->setEmail($email);
        $this->pendingAuthentication->setTotp(null);
        $this->pendingAuthentication->setUserId(null);
        $this->pendingAuthentication->setAuthenticatorRequired(false);
        $this->sessionService->set(self::PENDING_REGISTRATION_USERNAME, $username);
        $this->sessionService->set(self::PENDING_CREATE_BY_EMAIL, false);

        return $this->otp->issueEmailOnly($email);
    }

    public function verify(AuthService $auth, string $mode, string $otp): array
    {
        $this->pendingAuthentication->setTotp($otp);
        $userId = $this->pendingAuthentication->getUserId();

        if ($this->verifySubmittedOtp($auth, $userId, $otp) === false) {
            return ['status' => self::STATUS_INVALID_OTP];
        }

        if ($this->shouldRequestAuthenticatorStep($auth) === true) {
            $this->pendingAuthentication->setTotp(null);
            $this->pendingAuthentication->setAuthenticatorRequired(true);
            return ['status' => self::STATUS_AUTHENTICATOR_CODE_REQUIRED];
        }

        $email = $this->pendingAuthentication->getEmail();
        if ($email === null) {
            return ['status' => self::STATUS_INVALID_OTP];
        }

        if ($userId === null) {
            $user = $this->createPendingUser($mode, $email);
            if ($user === null) {
                return ['status' => self::STATUS_INVALID_OTP];
            }
            $userId = (int) $user->getId();
            $this->pendingAuthentication->setUserId($userId);
        }

        $auth->loginUser($userId);

        return ['status' => self::STATUS_AUTHENTICATED, 'userId' => $userId];
    }

    private function verifySubmittedOtp(AuthService $auth, ?int $userId, string $otp): bool
    {
        if ($userId === null) {
            return $this->otp->verifyEmailOnly($otp);
        }

        if ($this->pendingAuthentication->isAuthenticatorRequired() === true) {
            return $this->authenticatorService->verify(
                $userId,
                AuthenticatorTOTPPurpose::LOGIN,
                $otp,
                $this->rateLimitContext($userId)
            );
        }

        return $this->emailTotpService->verify(
            $userId,
            EmailTOTPPurpose::LOGIN,
            $otp,
            $this->rateLimitContext($userId)
        );
    }

    private function shouldRequestAuthenticatorStep(AuthService $auth): bool
    {
        return $this->pendingAuthentication->isTwoStepRequired() === true
            && $this->pendingAuthentication->isAuthenticatorRequired() === false;
    }

    public function verifyRecoveryCode(
        AuthService $auth,
        string $submittedCode,
        AuthenticationRateLimitContext $rateLimitContext
    ): array {
        $userId = $this->pendingAuthentication->getUserId();
        if ($userId === null
            || $this->pendingAuthentication->isAuthenticatorRequired() === false
            || $this->recoveryCodeService === null
        ) {
            return ['status' => self::STATUS_INVALID_RECOVERY_CODE];
        }

        $result = $this->recoveryCodeService->consumeActiveCode(
            $userId,
            $submittedCode,
            $rateLimitContext,
            AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN
        );
        if ($result->status === RecoveryCodeConsumptionResult::STATUS_RATE_LIMITED) {
            return [
                'status' => RecoveryCodeConsumptionResult::STATUS_RATE_LIMITED,
                'retryAfterSeconds' => $result->retryAfterSeconds,
            ];
        }
        if ($result->isConsumed() === false) {
            return ['status' => self::STATUS_INVALID_RECOVERY_CODE];
        }

        $email = $this->pendingAuthentication->getEmail();
        if ($email === null) {
            return ['status' => self::STATUS_INVALID_RECOVERY_CODE];
        }

        $auth->loginUser($userId);
        $this->emailService->sendSecurityNotification(
            $email,
            'Recovery code used',
            'A recovery code was used to sign in to your account. If this was not you, secure your account immediately.'
        );

        return [
            'status' => self::STATUS_AUTHENTICATED,
            'userId' => $userId,
            'remainingCount' => $result->remainingCount,
        ];
    }

    private function createPendingUser(string $mode, string $email): ?User
    {
        $ipAddress = (string) $this->sessionService->get('_IPaddress');
        if ($mode === self::MODE_REGISTER) {
            $username = $this->sessionService->get(self::PENDING_REGISTRATION_USERNAME);
            if (is_string($username) === false || trim($username) === '') {
                return null;
            }
            return $this->userService->createUser($username, $email, $ipAddress);
        }

        if ((bool) $this->sessionService->get(self::PENDING_CREATE_BY_EMAIL, false) === true) {
            return $this->userService->createUserByEmail($email, $ipAddress);
        }

        return null;
    }

    private function rateLimitContext(int $userId): AuthenticationRateLimitContext
    {
        $ipAddress = (string) $this->sessionService->get('_IPaddress', '0.0.0.0');
        $sessionIdentifier = (string) $this->sessionService->get('_security_rate_limit_id', '');
        if ($sessionIdentifier === '') {
            $sessionIdentifier = bin2hex(random_bytes(16));
            $this->sessionService->set('_security_rate_limit_id', $sessionIdentifier);
        }
        return AuthenticationRateLimitContext::forUser($userId, $ipAddress, $sessionIdentifier);
    }
}
