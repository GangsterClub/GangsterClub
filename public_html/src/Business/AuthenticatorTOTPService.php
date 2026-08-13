<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserAuthenticatorTOTPRepository;

class AuthenticatorTOTPService
{
    private TOTPService $totpService;

    private UserAuthenticatorTOTPRepository $repository;

    public function __construct(
        TOTPService $totpService,
        UserAuthenticatorTOTPRepository $repository,
        private readonly AuthenticationRateLimitService $rateLimitService
    ) {
        $this->totpService = $totpService;
        $this->repository = $repository;
    }

    public function hasEnabledAuthenticator(int $userId): bool
    {
        return $this->repository->findByUserId($userId) !== false;
    }

    public function generateSecret(int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): string
    {
        return $this->totpService->generateSecret($digits, $period);
    }

    public function verifyPendingSecret(
        int $userId,
        AuthenticatorTOTPPurpose $purpose,
        string $secret,
        string $submittedCode,
        AuthenticationRateLimitContext $context,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): bool {
        $this->assertContext($userId, $context);
        $this->requirePermit($context, $purpose);
        $isValid = $this->totpService->verifyTOTP($secret, $submittedCode, $digits, $period);
        if ($isValid === true) {
            $this->resetPressure($context, $purpose);
        }
        return $isValid;
    }

    public function generateProvisioningUri(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): string
    {
        return $this->totpService->generateProvisioningUri($secret, $label, $issuer, $digits, $period);
    }

    public function generateQRCode(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): string
    {
        return $this->totpService->generateQRCode($secret, $label, $issuer, $digits, $period);
    }

    public function enableAuthenticator(int $userId, string $secret, int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): bool
    {
        return $this->repository->upsertSecret($userId, $secret, $digits, $period);
    }

    public function disableAuthenticator(int $userId): bool
    {
        return $this->repository->deleteByUserId($userId);
    }

    public function verify(
        int $userId,
        AuthenticatorTOTPPurpose $purpose,
        string $submittedCode,
        AuthenticationRateLimitContext $context
    ): bool
    {
        $this->assertContext($userId, $context);
        $this->requirePermit($context, $purpose);
        $record = $this->repository->findByUserId($userId);
        if ($record === false) {
            return false;
        }

        $digits = (int) ($record->digits ?? AUTHENTICATOR_TOTP_DIGITS);
        $period = (int) ($record->period ?? AUTHENTICATOR_TOTP_PERIOD);
        $isValid = $this->totpService->verifyTOTP($record->secret, $submittedCode, $digits, $period);
        if ($isValid === true) {
            $this->repository->touchLastVerified($userId);
            $this->resetPressure($context, $purpose);
        }

        return $isValid;
    }

    private function assertContext(int $userId, AuthenticationRateLimitContext $context): void
    {
        if ($userId <= 0 || $context->matchesUserId($userId) === false) {
            throw new \InvalidArgumentException('The authenticator owner must match the rate-limit account identity.');
        }
    }

    private function requirePermit(AuthenticationRateLimitContext $context, AuthenticatorTOTPPurpose $purpose): void
    {
        $decision = $this->rateLimitService->consumeAttempt(
            $context,
            AuthenticationRateLimitAction::AUTHENTICATOR_TOTP_VERIFY,
            $purpose->rateLimitPurpose(),
            $this->verificationPolicies()
        );
        if ($decision->allowed === false) {
            throw new RateLimitExceededException(
                AuthenticationRateLimitAction::AUTHENTICATOR_TOTP_VERIFY->value,
                $decision->retryAfterSeconds ?? 60
            );
        }
    }

    private function resetPressure(AuthenticationRateLimitContext $context, AuthenticatorTOTPPurpose $purpose): void
    {
        $this->rateLimitService->resetAfterSuccessfulVerification(
            $context,
            AuthenticationRateLimitAction::AUTHENTICATOR_TOTP_VERIFY,
            $purpose->rateLimitPurpose()
        );
    }

    /** @return array<string, RateLimitPolicy> */
    private function verificationPolicies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value => new RateLimitPolicy(5, 900, 60),
            AuthenticationRateLimitBucketDimension::CHALLENGE->value => new RateLimitPolicy(5, 900, 60),
            AuthenticationRateLimitBucketDimension::SESSION->value => new RateLimitPolicy(10, 900, 60),
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new RateLimitPolicy(50, 900, 60),
        ];
    }
}
