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

    public function createUser(string $username, string $email, string $ipAddress): bool
    {
        $userRecord = [
            'username' => $username,
            'email' => $email,
            'ip_address' => $ipAddress,
        ];

        return $this->dbh->table('user')->insert($userRecord);
    }

    public function createUserByEmail(string $email, string $ipAddress, string $username): bool
    {
        return $this->createUser($username, $email, $ipAddress);
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

    public function getBrowserSessionVersion(int $userId): ?int
    {
        $user = $this->findById($userId);
        return $user === false ? null : (int) ($user->browser_session_version ?? 1);
    }

    public function incrementBrowserSessionVersion(int $userId): ?int
    {
        $user = $this->dbh->table('user')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first();
        if ($user === false) {
            return null;
        }

        $nextVersion = (int) ($user->browser_session_version ?? 1) + 1;
        $updated = $this->dbh->table('user')
            ->where('id', $userId)
            ->updateAffected([
                'browser_session_version' => $nextVersion,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $updated === 1 ? $nextVersion : null;
    }
}
