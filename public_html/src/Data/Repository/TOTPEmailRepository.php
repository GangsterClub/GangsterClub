<?PHP

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class TOTPEmailRepository
{
    /**
     * Summary of dbh
     * @var Connection
     */
    private Connection $dbh;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(\app\Container\Application $application)
    {
        $this->dbh = $application->get('dbh');
    }

    /**
     * Store a new OTP in the database.
     *
     * @param int $userId
     * @param string $otp
     * @param float $expiresAt
     * @return void
     */
    public function storeOTP(int $userId, string $otp, float $expiresAt): void
    {
        $otpRecord = [
            'user_id' => $userId,
            'totp' => $otp,
            'expires_at' => $expiresAt,
        ];

        $this->dbh->table('totp_email')->insert($otpRecord);
    }

    /**
     * Find a valid OTP for the user that hasn't expired.
     *
     * @param int $userId
     * @param string $otp
     * @return object|null The OTP record if valid, null otherwise.
     */
    public function findValidOTP(int $userId, string $otp): ?object
    {
        return $this->dbh->table('totp_email')
            ->where('user_id', $userId)
            ->where('totp', $otp)
            ->where('expires_at', '>=', microtime(true))
            ->first();
    }

    /**
     * Delete an OTP by its ID after successful use.
     *
     * @param int $otpId
     * @return void
     */
    public function deleteOTP(int $otpId): void
    {
        $this->dbh->table('totp_email')
            ->where('id', $otpId)
            ->delete();
    }
}
