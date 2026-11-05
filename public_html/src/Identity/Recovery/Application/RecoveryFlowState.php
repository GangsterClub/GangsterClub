<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

interface RecoveryFlowState
{
    public function getChallengeBinding(): string;

    public function snapshot(): RecoveryFlowSnapshot;

    public function setPendingRecoverySetId(?int $setId): void;

    public function markRecoveryCodesDelivered(int $setId): void;

    public function setPendingAuthenticatorSecret(?string $secret): void;

    public function setChallenge(string $token, string $purpose): void;

    public function clear(): void;
}
