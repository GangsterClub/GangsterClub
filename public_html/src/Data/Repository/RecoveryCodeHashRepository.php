<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

final class RecoveryCodeHashRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function insertAll(int $setId, array $hashes, string $createdAt): void
    {
        foreach ($hashes as $hash) {
            $this->connection->table('recovery_code')->insert([
                'recovery_code_set_id' => $setId,
                'code_hash' => $hash,
                'created_at' => $createdAt,
            ]);
        }
    }

    public function consume(int $setId, string $hash, string $usedAt): bool
    {
        return $this->connection->table('recovery_code')
            ->where('recovery_code_set_id', $setId)->where('code_hash', $hash)
            ->where('used_at', 'IS', null)->updateAffected(['used_at' => $usedAt]) === 1;
    }

    public function countUnused(int $setId): int
    {
        return $this->connection->table('recovery_code')
            ->where('recovery_code_set_id', $setId)->where('used_at', 'IS', null)->count();
    }
}
