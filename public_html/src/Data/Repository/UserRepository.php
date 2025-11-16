<?PHP

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class UserRepository
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
     * Summary of findById
     * @param int $userId
     * @return object|false
     */
    public function findById(int $userId): object|false
    {
        return $this->dbh->table('user')
            ->where('id', $userId)
            ->first();
    }

    /**
     * Summary of findByUsername
     * @param string $username
     * @return object|false
     */
    public function findByUsername(string $username): object|false
    {
        return $this->dbh->table('user')
            ->where('username', $username)
            ->first();
    }

    /**
     * Summary of findByEmail
     * @param string $email
     * @return object|false
     */
    public function findByEmail(string $email): object|false
    {
        return $this->dbh->table('user')
            ->where('email', $email)
            ->first();
    }

    /**
     * Summary of createUserByEmail
     * @param string $email
     * @param string $ipAddress
     * @return bool
     */
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
