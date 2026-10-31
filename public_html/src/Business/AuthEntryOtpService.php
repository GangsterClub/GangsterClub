<?PHP

declare(strict_types=1);

namespace src\Business;

use app\Service\SessionService;

final class AuthEntryOtpService
{
    private const PENDING_EMAIL_ONLY_SECRET = 'PENDING_EMAIL_ONLY_TOTP_SECRET';
    private const PENDING_EMAIL_ONLY_PURPOSE = 'PENDING_EMAIL_ONLY_TOTP_PURPOSE';
    private const PENDING_EMAIL_ONLY_EMAIL = 'PENDING_EMAIL_ONLY_TOTP_EMAIL';

    public function __construct(
        private readonly EmailTOTPService $emailTotpService,
        private readonly TOTPService $totpService,
        private readonly EmailService $emailService,
        private readonly SessionService $sessionService,
        private readonly AuthenticationRateLimitService $rateLimitService,
        private readonly AuthenticationRateLimitContextProvider $rateLimitContexts
    ) {
    }

    public function issuePersisted(
        int $userId,
        string $email,
        AuthenticationRateLimitContext $context
    ): array {
        $this->requireSharedRecipientPermit($this->rateLimitContexts->forEmail($email));
        $issued = $this->emailTotpService->issue($userId, EmailTOTPPurpose::LOGIN, $context);
        if ($this->emailService->sendEmailTOTP($email, $issued->code) === true) {
            return ['status' => AuthEntryService::STATUS_EMAIL_OTP_SENT];
        }
        $this->emailTotpService->cancelIssued($issued);
        return ['status' => AuthEntryService::STATUS_SEND_ERROR];
    }

    public function issueEmailOnly(
        string $email,
        AuthenticationRateLimitPurpose $purpose
    ): array {
        $context = $this->rateLimitContexts->forEmail($email);
        $this->requireEmailOnlyIssuancePermit($context, $purpose);
        $secret = $this->totpService->generateSecret(TOTP_DIGITS, TOTP_PERIOD);
        $this->sessionService->set(self::PENDING_EMAIL_ONLY_SECRET, $secret);
        $this->sessionService->set(self::PENDING_EMAIL_ONLY_PURPOSE, $purpose->value);
        $this->sessionService->set(
            self::PENDING_EMAIL_ONLY_EMAIL,
            AuthenticationRateLimitAccountIdentifier::normalizeEmail($email)
        );
        $otp = $this->totpService->generateTOTP($secret, TOTP_DIGITS, TOTP_PERIOD);
        return [
            'status' => $this->emailService->sendEmailTOTP($email, $otp) === true
                ? AuthEntryService::STATUS_EMAIL_OTP_SENT
                : AuthEntryService::STATUS_SEND_ERROR,
        ];
    }

    public function verifyEmailOnly(
        string $otp,
        AuthenticationRateLimitPurpose $expectedPurpose
    ): ?string {
        $secret = $this->sessionService->get(self::PENDING_EMAIL_ONLY_SECRET);
        $purposeValue = $this->sessionService->get(self::PENDING_EMAIL_ONLY_PURPOSE);
        $purpose = is_string($purposeValue) ? AuthenticationRateLimitPurpose::tryFrom($purposeValue) : null;
        $email = $this->sessionService->get(self::PENDING_EMAIL_ONLY_EMAIL);
        if (is_string($secret) === false
            || $secret === ''
            || $purpose !== $expectedPurpose
            || is_string($email) === false
            || $email === ''
        ) {
            return null;
        }

        $context = $this->rateLimitContexts->forEmail($email);
        $this->requirePermit(
            $context,
            AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY,
            $purpose,
            AuthenticationRateLimitPolicies::emailVerification()
        );
        $isValid = $this->totpService->verifyTOTP($secret, $otp, TOTP_DIGITS, TOTP_PERIOD);
        if ($isValid === false) {
            return null;
        }
        // Session middleware holds the native session lock through request completion,
        // serializing this reset-and-consume sequence for a single browser session.
        $this->rateLimitService->resetAfterSuccessfulVerification(
            $context,
            AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY,
            $purpose
        );
        $this->sessionService->remove(self::PENDING_EMAIL_ONLY_SECRET);
        $this->sessionService->remove(self::PENDING_EMAIL_ONLY_PURPOSE);
        $this->sessionService->remove(self::PENDING_EMAIL_ONLY_EMAIL);
        return $email;
    }

    private function requireSharedRecipientPermit(AuthenticationRateLimitContext $context): void
    {
        $this->requirePermit(
            $context,
            AuthenticationRateLimitAction::EMAIL_RECIPIENT_ISSUE,
            AuthenticationRateLimitPurpose::EMAIL_RECIPIENT,
            AuthenticationRateLimitPolicies::emailIssuance()
        );
    }

    private function requireEmailOnlyIssuancePermit(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitPurpose $purpose
    ): void {
        $decision = $this->rateLimitService->consumeAttempts($context, [
            [
                'action' => AuthenticationRateLimitAction::EMAIL_RECIPIENT_ISSUE,
                'purpose' => AuthenticationRateLimitPurpose::EMAIL_RECIPIENT,
                'policies' => AuthenticationRateLimitPolicies::emailIssuance(),
            ],
            [
                'action' => AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE,
                'purpose' => $purpose,
                'policies' => AuthenticationRateLimitPolicies::emailIssuance(),
            ],
        ]);
        if ($decision->allowed === false) {
            throw new RateLimitExceededException(
                AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE->value,
                $decision->retryAfterSeconds ?? 60
            );
        }
    }

    /** @param array<string, RateLimitPolicy> $policies */
    private function requirePermit(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitAction $action,
        AuthenticationRateLimitPurpose $purpose,
        array $policies
    ): void {
        $decision = $this->rateLimitService->consumeAttempt($context, $action, $purpose, $policies);
        if ($decision->allowed === false) {
            throw new RateLimitExceededException($action->value, $decision->retryAfterSeconds ?? 60);
        }
    }
}
