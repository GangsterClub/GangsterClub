<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryFlowChallengeResult
{
    public const ACTIVE = 'active';
    public const UNAVAILABLE = 'unavailable';
    public const EXPIRED = 'expired';
    public const PENDING_SECRET_MISSING = 'pending_secret_missing';

    public function __construct(
        public readonly string $status,
        public readonly ?object $challenge = null
    ) {
    }
}
