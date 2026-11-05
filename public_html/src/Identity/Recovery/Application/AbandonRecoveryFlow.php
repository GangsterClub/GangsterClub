<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\RecoveryCodeService;

final class AbandonRecoveryFlow
{
    public function __construct(
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly AuthenticationChallengeService $challenges,
        private readonly RecoveryFlowState $flow
    ) {
    }

    public function abandon(int $userId, string $reason = 'challenge_expired'): void
    {
        $snapshot = $this->flow->snapshot();
        if ($snapshot->pendingRecoverySetId !== null) {
            $this->recoveryCodes->invalidatePendingSet($userId, $snapshot->pendingRecoverySetId, $reason);
        }

        if ($snapshot->challengeToken !== null && $snapshot->challengePurpose !== null) {
            $this->challenges->cancel(
                $snapshot->challengeToken,
                $this->flow->getChallengeBinding(),
                $snapshot->challengePurpose
            );
        }

        $this->flow->clear();
    }
}
