<?php

declare(strict_types=1);

namespace src\Business;

final class RateLimitDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remainingAttempts,
        public readonly ?int $retryAfterSeconds,
        public readonly array $blockedDimensions = []
    ) {
    }
}
