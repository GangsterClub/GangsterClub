<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryCodeGenerationResult
{
    public function __construct(
        public readonly int $setId,
        public readonly array $codes,
        public readonly \DateTimeImmutable $expiresAt
    ) {
    }
}
