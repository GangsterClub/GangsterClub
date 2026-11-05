<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

final readonly class RecoveryActionInput
{
    public function __construct(
        public ?RecoveryAction $action = null,
        public string $emailCode = '',
        public string $authenticatorCode = '',
        public string $recoveryCode = '',
        public bool $replacementConfirmed = false,
        public bool $codesSaved = false
    ) {
    }
}
