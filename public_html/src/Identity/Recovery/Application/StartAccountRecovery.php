<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;

final class StartAccountRecovery
{
    public function __construct(
        private readonly UserService $users,
        private readonly RecoveryFeatureService $recovery,
        private readonly AuthenticatorTOTPService $authenticators,
        private readonly AuthenticationChallengeService $challenges,
        private readonly RecoveryFlowState $flow,
        private readonly RecoveryIdentityContext $identity,
        private readonly AbandonRecoveryFlow $abandon,
        private readonly IssueRecoveryEmailVerification $emailVerification
    ) {
    }

    public function start(RecoveryCeremony $ceremony): RecoveryResult
    {
        $userId = $this->identity->authenticatedUserId();
        $user = $userId === null ? null : $this->users->getUserById($userId);
        if ($user === null) {
            return new RecoveryResult(RecoveryOutcome::UNAUTHENTICATED, '', RecoveryMode::ACCOUNT);
        }

        if ($ceremony === RecoveryCeremony::ENROLLMENT) {
            return $this->startEnrollment($user);
        }

        return $this->startRecoveryCodes($userId, $ceremony);
    }

    private function startEnrollment(\src\Entity\User $user): RecoveryResult
    {
        if ($this->authenticators->hasEnabledAuthenticator($user->getId()) === true) {
            return new RecoveryResult(
                RecoveryOutcome::AUTHENTICATOR_ALREADY_ENABLED,
                'already_enabled', RecoveryMode::ACCOUNT, RecoveryCeremony::ENROLLMENT->value
            );
        }

        $this->abandon->abandon($user->getId());
        $challenge = $this->challenges->start(
            $user->getId(),
            RecoveryCeremony::ENROLLMENT->value,
            $this->flow->getChallengeBinding()
        );
        $this->flow->setChallenge($challenge['token'], $challenge['purpose']);
        return $this->emailVerification->issue(
            $user,
            RecoveryCeremony::ENROLLMENT,
            RecoveryMode::ACCOUNT,
            $challenge['token']
        );
    }

    private function startRecoveryCodes(int $userId, RecoveryCeremony $ceremony): RecoveryResult
    {
        $generation = $this->recovery->getAuthenticatorGeneration($userId);
        $activeSetId = $this->recovery->getActiveRecoverySetId($userId);
        $available = $ceremony === RecoveryCeremony::INITIAL_CODES
            ? $generation !== null && $activeSetId === null
            : $ceremony === RecoveryCeremony::REPLACEMENT_CODES
                && $generation !== null && $activeSetId !== null;
        if ($available === false) {
            return new RecoveryResult(RecoveryOutcome::ACTION_UNAVAILABLE, 'unavailable', RecoveryMode::ACCOUNT, $ceremony->value);
        }

        $this->abandon->abandon($userId);
        $challenge = $this->challenges->start(
            $userId,
            $ceremony->value,
            $this->flow->getChallengeBinding(),
            $generation,
            $activeSetId
        );
        $this->flow->setChallenge($challenge['token'], $challenge['purpose']);
        return new RecoveryResult(
            RecoveryOutcome::REAUTHENTICATION_PENDING,
            'fresh_reauthentication_pending', RecoveryMode::ACCOUNT, $ceremony->value
        );
    }
}
