<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class AuthenticationRateLimitRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findForUpdate(string $bucketHash, string $purpose): object|false
    {
        return $this->connection->table('authentication_rate_limit')
            ->where('bucket_hash', $bucketHash)
            ->where('purpose', $purpose)
            ->lockForUpdate()
            ->first();
    }

    public function insert(string $bucketHash, string $purpose, string $windowStartedAt): void
    {
        $this->connection->table('authentication_rate_limit')->insert([
            'bucket_hash' => $bucketHash,
            'purpose' => $purpose,
            'attempt_count' => 1,
            'window_started_at' => $windowStartedAt,
        ]);
    }

    public function update(
        string $bucketHash,
        string $purpose,
        int $attemptCount,
        string $windowStartedAt,
        ?string $blockedUntil,
        string $updatedAt
    ): void {
        $this->connection->table('authentication_rate_limit')
            ->where('bucket_hash', $bucketHash)
            ->where('purpose', $purpose)
            ->update([
                'attempt_count' => $attemptCount,
                'window_started_at' => $windowStartedAt,
                'blocked_until' => $blockedUntil,
                'updated_at' => $updatedAt,
            ]);
    }

    public function delete(string $bucketHash, string $purpose): void
    {
        $this->connection->table('authentication_rate_limit')
            ->where('bucket_hash', $bucketHash)
            ->where('purpose', $purpose)
            ->delete();
    }
}
