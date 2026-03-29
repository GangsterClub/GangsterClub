<?PHP

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class UserRepository
{
    private Connection $dbh;

    public function __construct(Connection $dbh)
    {
        $this->dbh = $dbh;
    }

    public function findById(int $userId): object|false
    {
        return $this->dbh->table('user')
            ->where('id', $userId)
            ->first();
    }

    public function findByUsername(string $username): object|false
    {
        return $this->dbh->table('user')
            ->where('username', $username)
            ->first();
    }

    public function findByEmail(string $email): object|false
    {
        return $this->dbh->table('user')
            ->where('email', $email)
            ->first();
    }

    public function createUserByEmail(string $email, string $ipAddress): bool
    {
        $userRecord = [
            'username' => bin2hex(openssl_random_pseudo_bytes(16)),
            'email' => $email,
            'ip_address' => $ipAddress,
        ];

        return $this->dbh->table('user')->insert($userRecord);
    }

    public function updateUsername(int $userId, string $username): bool
    {
        return $this->dbh->table('user')
            ->where('id', $userId)
            ->update([
                'username' => $username,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function updateEmail(int $userId, string $email): bool
    {
        return $this->dbh->table('user')
            ->where('id', $userId)
            ->update([
                'email' => $email,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
