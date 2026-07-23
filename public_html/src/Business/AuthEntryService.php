<?PHP

declare(strict_types=1);

namespace src\Business;

use app\Http\Response;
use app\Service\AuthService;
use app\Service\JWTService;
use app\Service\SessionService;
use src\Entity\User;

class AuthEntryService
{
    public const MODE_LOGIN = 'login';
    public const MODE_REGISTER = 'register';
    public const STATUS_EMAIL_OTP_SENT = 'email_otp_sent';
    public const STATUS_APP_MFA_REQUIRED = 'app_mfa_required';
    public const STATUS_VALIDATION_ERROR = 'validation_error';
    public const STATUS_SEND_ERROR = 'send_error';
    public const STATUS_INVALID_OTP = 'invalid_otp';
    public const STATUS_AUTHENTICATED = 'authenticated';
    public const STATUS_AUTHORIZATION_RESPONSE = 'authorization_response';

    private const PENDING_REGISTRATION_USERNAME = 'PENDING_REGISTRATION_USERNAME';
    private const PENDING_CREATE_BY_EMAIL = 'PENDING_CREATE_BY_EMAIL';
    private const PENDING_EMAIL_ONLY_SECRET = 'PENDING_EMAIL_ONLY_TOTP_SECRET';

    public function __construct(
        private readonly UserService $userService,
        private readonly MFATOTPService $mfaService,
        private readonly TOTPEmailService $totpEmailService,
        private readonly TOTPService $totpService,
        private readonly EmailService $emailService,
        private readonly JWTService $jwtService,
        private readonly SessionService $sessionService
    ) {
    }

    public function beginLogin(AuthService $auth, string $email): array
    {
        $identifier = trim($email);
        $user = $this->userService->getUserByEmail($identifier);
        if ($user === null) {
            $user = $this->userService->getUserByUsername($identifier);
        }

        $auth->setPendingLoginTotp(null);
        $auth->setLoginMfaRequired(false);

        if ($user !== null) {
            $email = $user->getEmail();
            $auth->setPendingLoginEmail($email);
            $userId = (int) $user->getId();
            $auth->setPendingUserId($userId);
            $this->sessionService->remove(self::PENDING_CREATE_BY_EMAIL);

            if ($this->mfaService->hasEnabledMfa($userId) === true) {
                $auth->setLoginMfaRequired(true);
                return ['status' => self::STATUS_APP_MFA_REQUIRED];
            }

            return $this->sendPersistedEmailOtp($userId, $email);
        }

        if ($identifier === '' || filter_var($identifier, FILTER_VALIDATE_EMAIL) === false) {
            $auth->setPendingLoginEmail(null);
            $auth->setPendingUserId(null);
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'provide-valid-email'];
        }

        $auth->setPendingLoginEmail($identifier);
        $auth->setPendingUserId(null);
        $this->sessionService->set(self::PENDING_CREATE_BY_EMAIL, true);
        return $this->sendEmailOnlyOtp($identifier);
    }

    public function beginRegistration(AuthService $auth, string $username, string $email): array
    {
        $username = trim($username);
        $email = trim($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'provide-valid-email'];
        }

        if ($username === '') {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'provide-valid-username'];
        }

        if ($this->userService->getUserByEmail($email) !== null) {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'duplicate-email'];
        }

        if ($this->userService->getUserByUsername($username) !== null) {
            return ['status' => self::STATUS_VALIDATION_ERROR, 'error' => 'duplicate-username'];
        }

        $auth->setPendingLoginEmail($email);
        $auth->setPendingLoginTotp(null);
        $auth->setPendingUserId(null);
        $auth->setLoginMfaRequired(false);
        $this->sessionService->set(self::PENDING_REGISTRATION_USERNAME, $username);
        $this->sessionService->set(self::PENDING_CREATE_BY_EMAIL, false);

        return $this->sendEmailOnlyOtp($email);
    }

    public function verify(AuthService $auth, string $mode, string $otp): array
    {
        $auth->setPendingLoginTotp($otp);
        $userId = $auth->getPendingUserId();

        if ($userId !== null && $auth->isLoginMfaRequired() === true) {
            $isValid = $this->mfaService->verifyCode($userId, $otp);
        } elseif ($userId !== null) {
            $isValid = $this->totpEmailService->verifyEmailTOTP($userId, $otp);
        } else {
            $isValid = $this->verifyEmailOnlyOtp($otp);
        }

        if ($isValid !== true) {
            return $this->refreshStoredJwtOrInvalid($auth);
        }

        $email = $auth->getPendingLoginEmail();
        if ($email === null) {
            return ['status' => self::STATUS_INVALID_OTP];
        }

        if ($userId === null) {
            $user = $this->createPendingUser($mode, $email);
            if ($user === null) {
                return ['status' => self::STATUS_INVALID_OTP];
            }
            $userId = (int) $user->getId();
            $auth->setPendingUserId($userId);
        }

        $jwtToken = $this->jwtService->authenticate($email, true);
        if ($jwtToken === false) {
            return ['status' => self::STATUS_INVALID_OTP];
        }

        $auth->loginUserWithToken($userId, $jwtToken);
        return ['status' => self::STATUS_AUTHENTICATED, 'userId' => $userId, 'jwtToken' => $jwtToken];
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

    private function sendPersistedEmailOtp(int $userId, string $email): array
    {
        $otp = $this->totpEmailService->generateEmailTOTP($userId);
        return $this->sendOtpEmail($email, $otp);
    }

    private function sendEmailOnlyOtp(string $email): array
    {
        $secret = $this->totpService->generateSecret(TOTP_DIGITS, TOTP_PERIOD);
        $this->sessionService->set(self::PENDING_EMAIL_ONLY_SECRET, $secret);
        $otp = $this->totpService->generateTOTP($secret, TOTP_DIGITS, TOTP_PERIOD);
        return $this->sendOtpEmail($email, $otp);
    }

    private function sendOtpEmail(string $email, string $otp): array
    {
        if ($this->emailService->sendTOTPEmail($email, $otp) === true) {
            return ['status' => self::STATUS_EMAIL_OTP_SENT];
        }

        return ['status' => self::STATUS_SEND_ERROR];
    }

    private function verifyEmailOnlyOtp(string $otp): bool
    {
        $secret = $this->sessionService->get(self::PENDING_EMAIL_ONLY_SECRET);
        if (is_string($secret) === false || $secret === '') {
            return false;
        }

        $isValid = $this->totpService->verifyTOTP($secret, $otp, TOTP_DIGITS, TOTP_PERIOD);
        if ($isValid === true) {
            $this->sessionService->remove(self::PENDING_EMAIL_ONLY_SECRET);
        }

        return $isValid;
    }

    private function refreshStoredJwtOrInvalid(AuthService $auth): array
    {
        $storedToken = $auth->getStoredJwtToken();
        if (is_string($storedToken) === true && $storedToken !== '') {
            $authorizationResult = $this->jwtService->authorize('Bearer ' . $storedToken);
            if ($authorizationResult instanceof Response) {
                return ['status' => self::STATUS_AUTHORIZATION_RESPONSE, 'response' => $authorizationResult];
            }

            if (is_array($authorizationResult) === true && isset($authorizationResult['token']) === true) {
                $auth->storeJwtToken($authorizationResult['token']);
            }
        }

        return ['status' => self::STATUS_INVALID_OTP];
    }
}
