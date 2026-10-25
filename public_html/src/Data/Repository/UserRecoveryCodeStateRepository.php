<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

final class UserRecoveryCodeStateRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function find(int $userId, bool $lock = false): object|false
    {
        $query = $this->connection->table('user_recovery_code_state')->where('user_id', $userId);
        return ($lock === true ? $query->lockForUpdate() : $query)->first();
    }

    public function getActiveSetId(int $userId, bool $lock = false): ?int
    {
        $state = $this->find($userId, $lock);
        return $state === false || $state->active_recovery_code_set_id === null
            ? null
            : (int) $state->active_recovery_code_set_id;
    }

    public function setActiveSet(int $userId, int $setId, string $now): void
    {
        if ($this->find($userId, true) === false) {
            $this->connection->table('user_recovery_code_state')->insert([
                'user_id' => $userId,
                'active_recovery_code_set_id' => $setId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }
        $this->connection->table('user_recovery_code_state')->where('user_id', $userId)->update([
            'active_recovery_code_set_id' => $setId, 'updated_at' => $now,
        ]);
    }

    public function clearActiveSet(int $userId, string $now): bool
    {
        if ($this->find($userId, true) === false) {
            return true;
        }
        return $this->connection->table('user_recovery_code_state')->where('user_id', $userId)
            ->updateAffected(['active_recovery_code_set_id' => null, 'updated_at' => $now]) === 1;
    }
}
