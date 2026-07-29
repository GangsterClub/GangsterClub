<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryCodeConsumptionResult
{
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_RATE_LIMITED = 'rate_limited';

    public function __construct(
        public readonly string $status,
        public readonly ?int $remainingCount = null,
        public readonly ?int $retryAfterSeconds = null
    ) {
    }

    public function isConsumed(): bool
    {
        return $this->status === self::STATUS_CONSUMED;
    }
}
