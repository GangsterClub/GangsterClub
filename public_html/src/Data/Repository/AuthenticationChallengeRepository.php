<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class AuthenticationChallengeRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(array $record): int
    {
        return $this->connection->table('authentication_challenge')->insertGetId([
            'token_hash' => $record['token_hash'],
            'user_id' => $record['user_id'],
            'purpose' => $record['purpose'],
            'state' => $record['state'],
            'session_binding_hash' => $record['session_binding_hash'],
            'baseline_authenticator_generation' => $record['baseline_authenticator_generation'] ?? null,
            'baseline_recovery_code_set_id' => $record['baseline_recovery_code_set_id'] ?? null,
            'expires_at' => $record['expires_at'],
        ]);
    }

    public function lockUser(int $userId): bool
    {
        return $this->connection->table('user')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first() !== false;
    }

    public function findBound(string $tokenHash, string $sessionBindingHash): object|false
    {
        return $this->connection->table('authentication_challenge')
            ->where('token_hash', $tokenHash)
            ->where('session_binding_hash', $sessionBindingHash)
            ->first();
    }

    public function findBoundForUpdate(string $tokenHash, string $sessionBindingHash): object|false
    {
        return $this->connection->table('authentication_challenge')
            ->where('token_hash', $tokenHash)
            ->where('session_binding_hash', $sessionBindingHash)
            ->lockForUpdate()
            ->first();
    }

    public function transition(
        int $id,
        string $expectedState,
        string $nextState,
        string $now,
        ?string $reauthenticatedAt = null,
        ?string $completedAt = null
    ): bool {
        $updates = [
            'state' => $nextState,
            'updated_at' => $now,
        ];
        if ($reauthenticatedAt !== null) {
            $updates['reauthenticated_at'] = $reauthenticatedAt;
        }
        if ($completedAt !== null) {
            $updates['completed_at'] = $completedAt;
        }

        return $this->connection->table('authentication_challenge')
            ->where('id', $id)
            ->where('state', $expectedState)
            ->where('expires_at', '>=', $now)
            ->where('completed_at', 'IS', null)
            ->where('cancelled_at', 'IS', null)
            ->updateAffected($updates) === 1;
    }

    public function cancelActiveForUserAndPurpose(int $userId, string $purpose, string $now): void
    {
        $this->connection->table('authentication_challenge')
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('completed_at', 'IS', null)
            ->where('cancelled_at', 'IS', null)
            ->update([
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function cancel(int $id, string $now): bool
    {
        return $this->connection->table('authentication_challenge')
            ->where('id', $id)
            ->where('completed_at', 'IS', null)
            ->where('cancelled_at', 'IS', null)
            ->updateAffected([
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }
}
