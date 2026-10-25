<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

final class RecoveryCodeSetRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function createPending(
        int $userId,
        string $purpose,
        int $authenticatorGeneration,
        ?int $challengeId,
        ?int $replacesSetId,
        string $displayedAt,
        string $expiresAt
    ): int {
        return $this->connection->table('recovery_code_set')->insertGetId([
            'user_id' => $userId,
            'purpose' => $purpose,
            'status' => 'pending',
            'authenticator_generation' => $authenticatorGeneration,
            'authentication_challenge_id' => $challengeId,
            'replaces_recovery_code_set_id' => $replacesSetId,
            'displayed_at' => $displayedAt,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findForUpdate(int $setId): object|false
    {
        return $this->connection->table('recovery_code_set')->where('id', $setId)->lockForUpdate()->first();
    }

    public function findActive(int $setId, int $userId, bool $lock = false): object|false
    {
        $query = $this->connection->table('recovery_code_set')
            ->where('id', $setId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('acknowledged_at', 'IS NOT', null);
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function activate(int $setId, string $acknowledgedAt): bool
    {
        return $this->connection->table('recovery_code_set')
            ->where('id', $setId)->where('status', 'pending')
            ->where('acknowledged_at', 'IS', null)->where('invalidated_at', 'IS', null)
            ->where('expires_at', '>=', $acknowledgedAt)
            ->updateAffected([
                'status' => 'active', 'acknowledged_at' => $acknowledgedAt, 'updated_at' => $acknowledgedAt,
            ]) === 1;
    }

    public function invalidate(int $setId, string $invalidatedAt): bool
    {
        return $this->connection->table('recovery_code_set')
            ->where('id', $setId)->where('status', 'IN', ['pending', 'active'])
            ->where('invalidated_at', 'IS', null)
            ->updateAffected([
                'status' => 'invalidated', 'invalidated_at' => $invalidatedAt, 'updated_at' => $invalidatedAt,
            ]) === 1;
    }
}
