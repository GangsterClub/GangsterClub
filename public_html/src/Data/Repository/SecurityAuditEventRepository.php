<?php

declare(strict_types=1);

namespace src\Data\Repository;

use src\Data\Connection;

class SecurityAuditEventRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function insert(string $eventType, ?int $userId, ?string $contextJson, string $createdAt): void
    {
        $this->connection->table('security_audit_event')->insert([
            'user_id' => $userId,
            'event_type' => $eventType,
            'context_json' => $contextJson,
            'created_at' => $createdAt,
        ]);
    }
}
