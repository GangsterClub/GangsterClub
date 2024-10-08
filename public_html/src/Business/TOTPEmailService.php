<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\TOTPEmailRepository;
use app\Service\SessionService;

class TOTPEmailService
{
    /**
     * @var TOTPService
     */
    protected TOTPService $totp;

    /**
     * @var TOTPEmailRepository
     */
    protected TOTPEmailRepository $totpEmailRepository;

    /**
     * Summary of sessionService
     * @var SessionService
     */
    protected SessionService $sessionService;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(\app\Container\Application $application)
    {
        $this->totp = new TOTPService();
        $this->totpEmailRepository = new TOTPEmailRepository($application->get('dbh'));
        $this->sessionService = $application->get('sessionService');
    }

    /**
     * Generate a {ENV:TOTP_DIGITS}-digit time-based {ENV:TOTP_PERIOD} TOTP for email and save it using the repository.
     *
     * @param int $userId
     * @return string The generated TOTP of {ENV:TOTP_DIGITS}-digits.
     */
    public function generateEmailTOTP(int $userId): string
    {
        $secret = $this->totp->generateSecret(TOTP_DIGITS, TOTP_PERIOD);
        $this->sessionService->set('TOTP_SECRET', $secret);
        $this->totpEmailRepository->storeTOTP(
            $userId,
            $secret,
            date('Y-m-d H:i:s', (time() + TOTP_PERIOD))
        );
        return $this->totp->generateTOTP($secret, TOTP_DIGITS, TOTP_PERIOD);
    }

    /**
     * Verify the provided TOTP for a user and delete it upon successful authentication.
     *
     * @param int $userId
     * @param string $totp
     * @return bool True if the TOTP is valid, false otherwise.
     */
    public function verifyEmailTOTP(int $userId, string $totp): bool
    {
        $secret = $this->sessionService->get('TOTP_SECRET');
        $totpRecord = $this->totpEmailRepository->findValidTOTP($userId, $secret);
        if ((bool) $totpRecord === false || strtotime($totpRecord->expires_at) < time()) {
            return false;
        }

        $isValid = $this->totp->verifyTOTP($totpRecord->totp_secret, $totp, TOTP_DIGITS, TOTP_PERIOD);
        if ((bool) $isValid === true) {
            $this->totpEmailRepository->deleteTOTP($totpRecord->id);
            $this->authenticateUser($userId);
        }

        return $isValid;
    }

    /**
     * Placeholder for user authentication after successful TOTP validation.
     *
     * @param int $userId
     * @return void
     */
    private function authenticateUser(int $userId): void
    {
        $session = $this->sessionService;
        $session->remove('UNAUTHENTICATED_UID');
        $session->set('UID', $userId);
        $session->regenerate();
    }
}
