<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

final class UserLockRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function lock(int $userId): bool
    {
        return $this->connection->table('user')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first() !== false;
    }
}
