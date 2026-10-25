<?PHP

declare(strict_types=1);

namespace src\Business;

use app\Service\SessionService;

final class AuthEntryOtpService
{
    private const PENDING_EMAIL_ONLY_SECRET = 'PENDING_EMAIL_ONLY_TOTP_SECRET';

    public function __construct(
        private readonly EmailTOTPService $emailTotpService,
        private readonly TOTPService $totpService,
        private readonly EmailService $emailService,
        private readonly SessionService $sessionService
    ) {
    }

    public function issuePersisted(
        int $userId,
        string $email,
        AuthenticationRateLimitContext $context
    ): array {
        $issued = $this->emailTotpService->issue($userId, EmailTOTPPurpose::LOGIN, $context);
        if ($this->emailService->sendEmailTOTP($email, $issued->code) === true) {
            return ['status' => AuthEntryService::STATUS_EMAIL_OTP_SENT];
        }
        $this->emailTotpService->cancelIssued($issued);
        return ['status' => AuthEntryService::STATUS_SEND_ERROR];
    }

    public function issueEmailOnly(string $email): array
    {
        $secret = $this->totpService->generateSecret(TOTP_DIGITS, TOTP_PERIOD);
        $this->sessionService->set(self::PENDING_EMAIL_ONLY_SECRET, $secret);
        $otp = $this->totpService->generateTOTP($secret, TOTP_DIGITS, TOTP_PERIOD);
        return [
            'status' => $this->emailService->sendEmailTOTP($email, $otp) === true
                ? AuthEntryService::STATUS_EMAIL_OTP_SENT
                : AuthEntryService::STATUS_SEND_ERROR,
        ];
    }

    public function verifyEmailOnly(string $otp): bool
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
}
