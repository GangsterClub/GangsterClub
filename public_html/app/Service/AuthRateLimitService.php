<?PHP

declare(strict_types=1);

namespace app\Service;

use src\Data\Connection;

class AuthRateLimitService
{
    public function __construct(private Connection $connection) {}

    public function allowAttempt(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $scope = $this->normalizeScope($scope);
        $identifierHash = $this->identifierHash($identifier);
        $pdo = $this->connection->getConnection();
        if ($pdo === null) {
            throw new \RuntimeException('Database connection is not available for auth rate limiting.');
        }

        $expiresAt = (new \DateTimeImmutable('+' . $windowSeconds . ' seconds'))->format('Y-m-d H:i:s');
        $insert = $pdo->prepare(
            'INSERT INTO auth_rate_limit (scope, identifier_hash, attempt_count, expires_at) VALUES (?, ?, 0, ?) '
            . 'ON DUPLICATE KEY UPDATE attempt_count = IF(expires_at <= CURRENT_TIMESTAMP, 0, attempt_count), '
            . 'expires_at = IF(expires_at <= CURRENT_TIMESTAMP, VALUES(expires_at), expires_at)'
        );
        $insert->execute([$scope, $identifierHash, $expiresAt]);

        $update = $pdo->prepare(
            'UPDATE auth_rate_limit SET attempt_count = attempt_count + 1 '
            . 'WHERE scope = ? AND identifier_hash = ? AND attempt_count < ? AND expires_at > CURRENT_TIMESTAMP'
        );
        $update->execute([$scope, $identifierHash, $maxAttempts]);

        return $update->rowCount() === 1;
    }

    private function normalizeScope(string $scope): string
    {
        return substr(strtolower(trim($scope)), 0, 32);
    }

    private function identifierHash(string $identifier): string
    {
        return hash('sha256', strtolower(trim($identifier)));
    }
}
