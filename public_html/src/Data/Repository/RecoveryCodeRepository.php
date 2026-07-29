<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class RecoveryCodeRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function lockUser(int $userId): bool
    {
        return $this->connection->table('user')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first() !== false;
    }

    public function createPendingSet(
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

    public function insertCodeHashes(int $setId, array $hashes, string $createdAt): void
    {
        foreach ($hashes as $hash) {
            $this->connection->table('recovery_code')->insert([
                'recovery_code_set_id' => $setId,
                'code_hash' => $hash,
                'created_at' => $createdAt,
            ]);
        }
    }

    public function findSetForUpdate(int $setId): object|false
    {
        return $this->connection->table('recovery_code_set')
            ->where('id', $setId)
            ->lockForUpdate()
            ->first();
    }

    public function findStateForUpdate(int $userId): object|false
    {
        return $this->connection->table('user_recovery_code_state')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
    }

    public function findActiveSetForUpdate(int $userId): object|false
    {
        $state = $this->findStateForUpdate($userId);
        if ($state === false || $state->active_recovery_code_set_id === null) {
            return false;
        }

        $set = $this->connection->table('recovery_code_set')
            ->where('id', (int) $state->active_recovery_code_set_id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('acknowledged_at', 'IS NOT', null)
            ->lockForUpdate()
            ->first();
        if ($set === false) {
            return false;
        }

        $generation = $this->findAuthenticatorGenerationForUpdate($userId);
        if ($generation === null || $generation !== (int) $set->authenticator_generation) {
            return false;
        }

        return $set;
    }

    public function findAuthenticatorGenerationForUpdate(int $userId): ?int
    {
        $record = $this->connection->table('user_authenticator_totp')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        return $record === false ? null : (int) $record->generation;
    }

    public function consumeUnusedHash(int $setId, string $hash, string $usedAt): bool
    {
        return $this->connection->table('recovery_code')
            ->where('recovery_code_set_id', $setId)
            ->where('code_hash', $hash)
            ->where('used_at', 'IS', null)
            ->updateAffected(['used_at' => $usedAt]) === 1;
    }

    public function countUnused(int $setId): int
    {
        return $this->connection->table('recovery_code')
            ->where('recovery_code_set_id', $setId)
            ->where('used_at', 'IS', null)
            ->count();
    }

    public function activateSet(int $setId, string $acknowledgedAt): bool
    {
        return $this->connection->table('recovery_code_set')
            ->where('id', $setId)
            ->where('status', 'pending')
            ->where('acknowledged_at', 'IS', null)
            ->where('invalidated_at', 'IS', null)
            ->where('expires_at', '>=', $acknowledgedAt)
            ->updateAffected([
                'status' => 'active',
                'acknowledged_at' => $acknowledgedAt,
                'updated_at' => $acknowledgedAt,
            ]) === 1;
    }

    public function invalidateSet(int $setId, string $invalidatedAt): bool
    {
        return $this->connection->table('recovery_code_set')
            ->where('id', $setId)
            ->where('status', 'IN', ['pending', 'active'])
            ->where('invalidated_at', 'IS', null)
            ->updateAffected([
                'status' => 'invalidated',
                'invalidated_at' => $invalidatedAt,
                'updated_at' => $invalidatedAt,
            ]) === 1;
    }

    public function setActiveSet(int $userId, int $setId, string $now): void
    {
        $state = $this->connection->table('user_recovery_code_state')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
        if ($state === false) {
            $this->connection->table('user_recovery_code_state')->insert([
                'user_id' => $userId,
                'active_recovery_code_set_id' => $setId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $this->connection->table('user_recovery_code_state')
            ->where('user_id', $userId)
            ->update([
                'active_recovery_code_set_id' => $setId,
                'updated_at' => $now,
            ]);
    }
}
