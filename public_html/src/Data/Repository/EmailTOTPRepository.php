<?PHP

declare(strict_types=1);

namespace src\Data\Repository;

use src\Business\EmailTOTPPurpose;
use src\Data\Connection;

class EmailTOTPRepository
{
    private Connection $dbh;

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
    public function storeTOTP(int $userId, EmailTOTPPurpose $purpose, string $secret, string $expiresAt): int
    {
        $totpRecord = [
            'user_id' => $userId,
            'purpose' => $purpose->value,
            'totp_secret' => $secret,
            'expires_at' => $expiresAt,
        ];

        return $this->dbh->table('email_totp')->insertGetId($totpRecord);
    }

    /**
     * Find a valid TOTP secret for the user that hasn't expired.
     *
     * @param int $userId
     * @param string $secret
     * @return object|false The TOTP record if valid, false otherwise.
     */
    public function findValidTOTP(int $userId, EmailTOTPPurpose $purpose, string $secret): object|false
    {
        return $this->dbh->table('email_totp')
            ->where('user_id', $userId)
            ->where('purpose', $purpose->value)
            ->where('totp_secret', $secret)
            ->where('expires_at', '>=', date('Y-m-d H:i:s'))
            ->first();
    }

    /**
     * Find all valid TOTP secrets for the user that haven't expired.
     *
     * @param int $userId
     * @return array
     */
    public function findAllValidTOTPs(int $userId, EmailTOTPPurpose $purpose): array
    {
        return $this->dbh->table('email_totp')
            ->where('user_id', $userId)
            ->where('purpose', $purpose->value)
            ->where('expires_at', '>=', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * Delete an TOTP secret by its ID after successful use.
     *
     * @param int $otpId
     * @return void
     */
    public function deleteTOTP(int $totpId): void
    {
        $this->dbh->table('email_totp')
            ->where('id', $totpId)
            ->delete();
    }

    public function consumeTOTP(int $totpId, int $userId, EmailTOTPPurpose $purpose): bool
    {
        return $this->dbh->table('email_totp')
            ->where('id', $totpId)
            ->where('user_id', $userId)
            ->where('purpose', $purpose->value)
            ->where('expires_at', '>=', date('Y-m-d H:i:s'))
            ->deleteAffected() === 1;
    }
}
