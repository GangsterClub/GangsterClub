<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

final readonly class RecoveryFlowSnapshot
{
    public function __construct(
        public ?string $challengeToken,
        public ?string $challengePurpose,
        public ?int $pendingRecoverySetId,
        public ?int $deliveredRecoverySetId,
        public ?string $pendingAuthenticatorSecret
    ) {
    }
}
