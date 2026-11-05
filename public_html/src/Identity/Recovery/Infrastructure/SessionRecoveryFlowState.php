<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Infrastructure;

use app\Service\SecurityFlowSession;
use src\Identity\Recovery\Application\RecoveryFlowState;
use src\Identity\Recovery\Application\RecoveryFlowSnapshot;

final class SessionRecoveryFlowState implements RecoveryFlowState
{
    public function __construct(private readonly SecurityFlowSession $session)
    {
    }

    public function getChallengeBinding(): string
    {
        return $this->session->getChallengeBinding();
    }

    public function snapshot(): RecoveryFlowSnapshot
    {
        return new RecoveryFlowSnapshot(
            $this->session->getChallengeToken(),
            $this->session->getChallengePurpose(),
            $this->session->getPendingRecoverySetId(),
            $this->session->getDeliveredRecoverySetId(),
            $this->session->getPendingAuthenticatorSecret()
        );
    }

    public function setPendingRecoverySetId(?int $setId): void
    {
        $this->session->setPendingRecoverySetId($setId);
    }

    public function markRecoveryCodesDelivered(int $setId): void
    {
        $this->session->markRecoveryCodesDelivered($setId);
    }

    public function setPendingAuthenticatorSecret(?string $secret): void
    {
        $this->session->setPendingAuthenticatorSecret($secret);
    }

    public function setChallenge(string $token, string $purpose): void
    {
        $this->session->setChallenge($token, $purpose);
    }

    public function clear(): void
    {
        $this->session->clear();
    }
}
