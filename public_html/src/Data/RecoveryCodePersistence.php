<?php

declare(strict_types=1);

namespace src\Data;

use src\Data\Repository\RecoveryCodeHashRepository;
use src\Data\Repository\RecoveryCodeSetRepository;
use src\Data\Repository\UserRecoveryCodeStateRepository;

final class RecoveryCodePersistence
{
    public function __construct(
        private readonly RecoveryCodeSetRepository $sets,
        private readonly RecoveryCodeHashRepository $codes,
        private readonly UserRecoveryCodeStateRepository $states
    ) {
    }

    public function sets(): RecoveryCodeSetRepository { return $this->sets; }
    public function codes(): RecoveryCodeHashRepository { return $this->codes; }
    public function states(): UserRecoveryCodeStateRepository { return $this->states; }
}
