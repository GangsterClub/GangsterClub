<?PHP

declare(strict_types=1);

namespace src\Data\Repository;

use RuntimeException;
use src\Data\Connection;

class UserEmailChangeRepository
{
    private Connection $dbh;

    private \PDO $pdo;

    public function __construct(Connection $dbh)
    {
        $this->dbh = $dbh;
        $connection = $dbh->getConnection();
        if ($connection instanceof \PDO === false) {
            throw new RuntimeException('Database connection unavailable.');
        }

        $this->pdo = $connection;
    }

    public function create(int $userId, string $newEmail, string $tokenHash, string $expiresAt): bool
    {
        return $this->dbh->table('user_email_change')->insert([
            'user_id' => $userId,
            'new_email' => $newEmail,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findLatestPendingByUserId(int $userId): object|false
    {
        $sql = 'SELECT * FROM user_email_change WHERE user_id = :user_id AND confirmed_at IS NULL ORDER BY created_at DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
        ]);

        return $stmt->fetch();
    }

    public function findByToken(string $tokenHash): object|false
    {
        $sql = 'SELECT * FROM user_email_change WHERE token_hash = :token_hash AND confirmed_at IS NULL LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'token_hash' => $tokenHash,
        ]);

        return $stmt->fetch();
    }

    public function markConfirmed(int $id): bool
    {
        return $this->dbh->table('user_email_change')
            ->where('id', $id)
            ->update([
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function deleteByUserId(int $userId): bool
    {
        return $this->dbh->table('user_email_change')
            ->where('user_id', $userId)
            ->delete();
    }

    public function deleteById(int $id): bool
    {
        return $this->dbh->table('user_email_change')
            ->where('id', $id)
            ->delete();
    }
}
