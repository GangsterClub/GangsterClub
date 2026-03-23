<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\TOTPEmailRepository;
use app\Service\SessionService;

class TOTPEmailService
{
    private const DEFAULT_SESSION_KEY = 'TOTP_SECRET';

    protected TOTPService $totp;

    protected TOTPEmailRepository $totpEmailRepository;

    protected SessionService $sessionService;

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
        return $this->generateEmailTOTPForSession($userId, self::DEFAULT_SESSION_KEY);
    }

    public function generateEmailTOTPForSession(int $userId, string $sessionKey): string
    {
        $secret = $this->totp->generateSecret(TOTP_DIGITS, TOTP_PERIOD);
        $this->sessionService->set($sessionKey, $secret);
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
        return $this->verifyEmailTOTPForSession($userId, $totp, self::DEFAULT_SESSION_KEY);
    }

    public function verifyEmailTOTPForSession(
        int $userId,
        string $totp,
        string $sessionKey
    ): bool {
        $candidates = $this->totpEmailRepository->findAllValidTOTPs($userId);

        $secret = $this->sessionService->get($sessionKey);
        if (is_string($secret) === true && $secret !== '') {
            $totpRecord = $this->totpEmailRepository->findValidTOTP($userId, $secret);

            if ($totpRecord !== false) {
                $candidates = array_merge(
                    [$totpRecord],
                    array_filter(
                        $candidates,
                        static fn ($candidate) => (int) $candidate->id !== (int) $totpRecord->id
                    )
                );
            }
        }

        foreach ($candidates as $totpRecord) {
            if (strtotime($totpRecord->expires_at) < time()) {
                continue;
            }

            $isValid = $this->totp->verifyTOTP($totpRecord->totp_secret, $totp, TOTP_DIGITS, TOTP_PERIOD);
            if ((bool) $isValid === true) {
                $this->totpEmailRepository->deleteTOTP((int) $totpRecord->id);
                $this->sessionService->remove($sessionKey);
                return true;
            }
        }

        return false;
    }
}
