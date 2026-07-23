<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserMFATOTPRepository;

class MFATOTPService
{
    private TOTPService $totpService;

    private UserMFATOTPRepository $repository;

    public function __construct(TOTPService $totpService, UserMFATOTPRepository $repository)
    {
        $this->totpService = $totpService;
        $this->repository = $repository;
    }

    public function hasEnabledMfa(int $userId): bool
    {
        return $this->repository->findByUserId($userId) !== false;
    }

    public function generateSecret(int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): string
    {
        return $this->totpService->generateSecret($digits, $period);
    }

    public function verifySecret(string $secret, string $code, int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): bool
    {
        return $this->totpService->verifyTOTP($secret, $code, $digits, $period);
    }

    public function generateProvisioningUri(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = MFA_TOTP_DIGITS,
        int $period = MFA_TOTP_PERIOD
    ): string
    {
        return $this->totpService->generateProvisioningUri($secret, $label, $issuer, $digits, $period);
    }

    public function generateQRCode(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = MFA_TOTP_DIGITS,
        int $period = MFA_TOTP_PERIOD
    ): string
    {
        return $this->totpService->generateQRCode($secret, $label, $issuer, $digits, $period);
    }

    public function enableMfa(int $userId, string $secret, int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): bool
    {
        return $this->repository->upsertSecret($userId, $secret, $digits, $period);
    }

    public function disableMfa(int $userId): bool
    {
        return $this->repository->deleteByUserId($userId);
    }

    public function verifyEnabledSecret(int $userId, string $code): bool
    {
        $record = $this->repository->findByUserId($userId);
        if ($record === false) {
            return false;
        }

        $digits = (int) ($record->digits ?? MFA_TOTP_DIGITS);
        $period = (int) ($record->period ?? MFA_TOTP_PERIOD);

        return $this->totpService->verifyTOTP($record->secret, $code, $digits, $period);
    }

    public function verifyCode(int $userId, string $code): bool
    {
        $record = $this->repository->findByUserId($userId);
        if ($record === false) {
            return false;
        }

        $digits = (int) ($record->digits ?? MFA_TOTP_DIGITS);
        $period = (int) ($record->period ?? MFA_TOTP_PERIOD);
        $isValid = $this->totpService->verifyTOTP($record->secret, $code, $digits, $period);
        if ($isValid === true) {
            $this->repository->touchLastVerified($userId);
        }

        return $isValid;
    }
}
