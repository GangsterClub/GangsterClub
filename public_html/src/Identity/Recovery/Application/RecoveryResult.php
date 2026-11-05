<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Entity\User;

final readonly class RecoveryResult
{
    /** @param list<string>|null $codes */
    public function __construct(
        public RecoveryOutcome $outcome,
        public string $state,
        public RecoveryMode $mode,
        public ?string $purpose = null,
        public ?User $user = null,
        public ?object $challenge = null,
        public ?array $codes = null,
        public ?int $remainingCount = null,
        public ?int $retryAfterSeconds = null
    ) {
    }
}
