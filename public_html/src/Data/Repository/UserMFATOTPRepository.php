<?PHP

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class UserMFATOTPRepository
{
    private Connection $dbh;

    public function __construct(Connection $dbh)
    {
        $this->dbh = $dbh;
    }

    public function findByUserId(int $userId): object|false
    {
        return $this->dbh->table('user_mfa_totp')
            ->where('user_id', $userId)
            ->first();
    }

    public function upsertSecret(int $userId, string $secret, int $digits, int $period): bool
    {
        $existing = $this->findByUserId($userId);
        $record = [
            'secret' => $secret,
            'digits' => $digits,
            'period' => $period,
            'enabled_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing === false) {
            $record['user_id'] = $userId;
            return $this->dbh->table('user_mfa_totp')->insert($record);
        }

        return $this->dbh->table('user_mfa_totp')
            ->where('user_id', $userId)
            ->update($record);
    }

    public function deleteByUserId(int $userId): bool
    {
        return $this->dbh->table('user_mfa_totp')
            ->where('user_id', $userId)
            ->delete();
    }

    public function touchLastVerified(int $userId): bool
    {
        return $this->dbh->table('user_mfa_totp')
            ->where('user_id', $userId)
            ->update([
                'last_verified_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
