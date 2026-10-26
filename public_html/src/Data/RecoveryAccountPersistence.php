<?php

declare(strict_types=1);

namespace src\Data;

use src\Data\Repository\UserAuthenticatorTOTPRepository;
use src\Data\Repository\UserLockRepository;
use src\Data\Repository\UserRepository;

final class RecoveryAccountPersistence
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserLockRepository $userLocks,
        private readonly UserAuthenticatorTOTPRepository $authenticators
    ) {
    }

    public function users(): UserRepository { return $this->users; }
    public function userLocks(): UserLockRepository { return $this->userLocks; }
    public function authenticators(): UserAuthenticatorTOTPRepository { return $this->authenticators; }
}
