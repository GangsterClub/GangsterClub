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
     * @param Connection $dbh
     */
    public function __construct(Connection $dbh)
    {
        $this->dbh = $dbh;
    }

    /**
     * Store a new TOTP secret in the database.
     *
     * @param int $userId
     * @param string $secret
     * @param string $expiresAt
     * @return void
     */
    public function storeTOTP(int $userId, string $secret, string $expiresAt): void
    {
        $totpRecord = [
            'user_id' => $userId,
            'totp_secret' => $secret,
            'expires_at' => $expiresAt,
        ];

        $this->dbh->table('totp_email')->insert($totpRecord);
    }

    /**
     * Find a valid TOTP secret for the user that hasn't expired.
     *
     * @param int $userId
     * @param string $secret
     * @return object|false The TOTP record if valid, null otherwise.
     */
    public function findValidTOTP(int $userId, string $secret): object|false
    {
        return $this->dbh->table('totp_email')
            ->where('user_id', $userId)
            ->where('totp_secret', $secret)
            ->where('expires_at', '>=', date('Y-m-d H:i:s'))
            ->first();
    }

    /**
     * Delete an TOTP secret by its ID after successful use.
     *
     * @param int $otpId
     * @return void
     */
    public function deleteTOTP(int $totpId): void
    {
        $this->dbh->table('totp_email')
            ->where('id', $totpId)
            ->delete();
    }
}
