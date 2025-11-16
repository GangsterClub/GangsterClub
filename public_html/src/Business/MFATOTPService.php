<?PHP

declare(strict_types=1);

namespace src\Business;

use app\Service\SessionService;
use src\Data\Repository\UserMFATOTPRepository;

class MFATOTPService
{
    private TOTPService $totpService;

    private UserMFATOTPRepository $repository;

    private SessionService $sessionService;

    public function __construct(\app\Container\Application $application)
    {
        $this->totpService = new TOTPService();
        $this->repository = new UserMFATOTPRepository($application->get('dbh'));
        $this->sessionService = $application->get('sessionService');
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

    public function enableMfa(int $userId, string $secret, int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): bool
    {
        return $this->repository->upsertSecret($userId, $secret, $digits, $period);
    }

    public function disableMfa(int $userId): bool
    {
        return $this->repository->deleteByUserId($userId);
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
            $this->authenticateUser($userId);
        }

        return $isValid;
    }

    private function authenticateUser(int $userId): void
    {
        $session = $this->sessionService;
        $session->remove('UNAUTHENTICATED_UID');
        $session->remove('login.mfa_required');
        $session->set('UID', $userId);
        $session->regenerate();
    }
}
