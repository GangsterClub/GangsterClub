<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserAuthenticatorTOTPRepository;

class AuthenticatorTOTPService
{
    private TOTPService $totpService;

    private UserAuthenticatorTOTPRepository $repository;

    public function __construct(TOTPService $totpService, UserAuthenticatorTOTPRepository $repository)
    {
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

    public function verifySecret(string $secret, string $code, int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): bool
    {
        return $this->totpService->verifyTOTP($secret, $code, $digits, $period);
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

    public function verifyEnabledSecret(int $userId, string $code): bool
    {
        $record = $this->repository->findByUserId($userId);
        if ($record === false) {
            return false;
        }

        $digits = (int) ($record->digits ?? AUTHENTICATOR_TOTP_DIGITS);
        $period = (int) ($record->period ?? AUTHENTICATOR_TOTP_PERIOD);

        return $this->totpService->verifyTOTP($record->secret, $code, $digits, $period);
    }

    public function verifyCode(int $userId, string $code): bool
    {
        $record = $this->repository->findByUserId($userId);
        if ($record === false) {
            return false;
        }

        $digits = (int) ($record->digits ?? AUTHENTICATOR_TOTP_DIGITS);
        $period = (int) ($record->period ?? AUTHENTICATOR_TOTP_PERIOD);
        $isValid = $this->totpService->verifyTOTP($record->secret, $code, $digits, $period);
        if ($isValid === true) {
            $this->repository->touchLastVerified($userId);
        }

        return $isValid;
    }
}
