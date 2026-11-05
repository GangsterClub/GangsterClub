<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;

final class StartLostAuthenticatorRecovery
{
    public function __construct(
        private readonly UserService $users,
        private readonly RecoveryFeatureService $recovery,
        private readonly AuthenticationChallengeService $challenges,
        private readonly RecoveryFlowState $flow,
        private readonly RecoveryIdentityContext $identity,
        private readonly AbandonRecoveryFlow $abandon,
        private readonly IssueRecoveryEmailVerification $emailVerification
    ) {
    }

    public function start(): RecoveryResult
    {
        $userId = $this->identity->pendingUserId();
        if ($userId === null
            || $this->identity->pendingAuthenticatorRequired() === false
            || $this->recovery->getActiveRecoverySetId($userId) === null
        ) {
            return $this->unavailable();
        }

        $user = $this->users->getUserById($userId);
        $generation = $this->recovery->getAuthenticatorGeneration($userId);
        $activeSetId = $this->recovery->getActiveRecoverySetId($userId);
        if ($user === null || $generation === null || $activeSetId === null) {
            return $this->unavailable();
        }

        $this->abandon->abandon($userId);
        $challenge = $this->challenges->start(
            $userId,
            RecoveryCeremony::LOST_AUTHENTICATOR->value,
            $this->flow->getChallengeBinding(),
            $generation,
            $activeSetId
        );
        $this->flow->setChallenge($challenge['token'], $challenge['purpose']);
        return $this->emailVerification->issue(
            $user,
            RecoveryCeremony::LOST_AUTHENTICATOR,
            RecoveryMode::LOST_AUTHENTICATOR,
            $challenge['token']
        );
    }

    private function unavailable(): RecoveryResult
    {
        return new RecoveryResult(
            RecoveryOutcome::LOST_RECOVERY_UNAVAILABLE,
            'unavailable', RecoveryMode::LOST_AUTHENTICATOR, RecoveryCeremony::LOST_AUTHENTICATOR->value
        );
    }
}
