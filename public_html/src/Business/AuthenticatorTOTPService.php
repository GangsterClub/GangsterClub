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
        AuthenticationRateLimitService $rateLimitService,
        ?AuthenticatorVerificationThrottle $throttle = null
    ) {
        $this->totpService = $totpService;
        $this->repository = $repository;
        $this->throttle = $throttle ?? new AuthenticatorVerificationThrottle($rateLimitService);
    }

    private readonly AuthenticatorVerificationThrottle $throttle;

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
        $this->throttle->requirePermit($userId, $context, $purpose);
        $isValid = $this->totpService->verifyTOTP($secret, $submittedCode, $digits, $period);
        if ($isValid === true) {
            $this->throttle->reset($context, $purpose);
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
        $this->throttle->requirePermit($userId, $context, $purpose);
        $record = $this->repository->findByUserId($userId);
        if ($record === false) {
            return false;
        }

        $digits = (int) ($record->digits ?? AUTHENTICATOR_TOTP_DIGITS);
        $period = (int) ($record->period ?? AUTHENTICATOR_TOTP_PERIOD);
        $isValid = $this->totpService->verifyTOTP($record->secret, $submittedCode, $digits, $period);
        if ($isValid === true) {
            $this->repository->touchLastVerified($userId);
            $this->throttle->reset($context, $purpose);
        }

        return $isValid;
    }

}
