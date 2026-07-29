<?php

declare(strict_types=1);

namespace src\Business;

final class RateLimitPolicy
{
    public function __construct(
        public readonly int $maximumAttempts,
        public readonly int $windowSeconds,
        public readonly int $blockSeconds
    ) {
        if ($maximumAttempts < 1 || $windowSeconds < 1 || $blockSeconds < 1) {
            throw new \InvalidArgumentException('Rate-limit policy values must be positive.');
        }
    }
}
