<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\RateLimitExceededException;
use src\Business\RecoveryFlowChallengeResult;
use src\Business\RecoveryFlowService;
use src\Business\UserService;
use src\Entity\User;

final class RecoveryFlowCoordinator
{
    public function __construct(
        private readonly UserService $users,
        private readonly RecoveryFlowService $recoveryFlow,
        private readonly RecoveryFlowState $flow,
        private readonly RecoveryIdentityContext $identity,
        private readonly AbandonRecoveryFlow $abandon,
        private readonly RecoveryVerification $verification,
        private readonly RecoveryCodeLifecycle $codeLifecycle
    ) {
    }

    public function handle(
        RecoveryMode $mode,
        RecoveryActionInput $input,
        bool $isPost
    ): RecoveryResult {
        $user = $this->user($mode);
        if ($user === null) {
            return new RecoveryResult(RecoveryOutcome::UNAUTHENTICATED, '', $mode);
        }

        $resolved = $this->resolve($user, $mode);
        if ($resolved instanceof RecoveryResult) {
            return $resolved;
        }
        if ($isPost === false) {
            return $this->activeResult(RecoveryOutcome::SHOW_FLOW, $resolved, $user, $mode);
        }

        return $this->dispatch($input, $user, $resolved, $mode);
    }

    private function user(RecoveryMode $mode): ?User
    {
        $userId = $mode === RecoveryMode::ACCOUNT
            ? $this->identity->authenticatedUserId() : $this->identity->pendingUserId();
        if ($mode === RecoveryMode::LOST_AUTHENTICATOR
            && $this->identity->pendingAuthenticatorRequired() === false
        ) {
            return null;
        }
        return $userId === null ? null : $this->users->getUserById($userId);
    }

    private function resolve(User $user, RecoveryMode $mode): object
    {
        $snapshot = $this->flow->snapshot();
        $token = $snapshot->challengeToken;
        $purpose = $snapshot->challengePurpose;
        $expectedPurpose = $mode === RecoveryMode::LOST_AUTHENTICATOR
            ? RecoveryCeremony::LOST_AUTHENTICATOR->value : $purpose;
        $resolved = $this->recoveryFlow->resolveChallenge(
            $user->getId(),
            $token,
            $purpose,
            $expectedPurpose,
            $this->flow->getChallengeBinding(),
            $snapshot->pendingAuthenticatorSecret
        );
        if ($resolved->status === RecoveryFlowChallengeResult::UNAVAILABLE) {
            return new RecoveryResult(RecoveryOutcome::FLOW_UNAVAILABLE, 'unavailable', $mode, $purpose);
        }
        if ($resolved->status === RecoveryFlowChallengeResult::EXPIRED) {
            $this->abandon->abandon($user->getId());
            return new RecoveryResult(RecoveryOutcome::FLOW_EXPIRED, 'unavailable', $mode, $purpose);
        }
        if ($resolved->status === RecoveryFlowChallengeResult::PENDING_SECRET_MISSING) {
            $this->abandon->abandon($user->getId());
            return new RecoveryResult(RecoveryOutcome::PENDING_SECRET_MISSING, 'unavailable', $mode, $purpose);
        }
        return $resolved->challenge
            ?? new RecoveryResult(RecoveryOutcome::FLOW_UNAVAILABLE, 'unavailable', $mode, $purpose);
    }

    private function dispatch(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        if ($input->action === null) {
            return $this->activeResult(RecoveryOutcome::INVALID_ACTION, $challenge, $user, $mode);
        }

        try {
            return match ($input->action) {
                RecoveryAction::VERIFY_EMAIL,
                RecoveryAction::VERIFY_AUTHENTICATOR,
                RecoveryAction::VERIFY_RECOVERY_CODE,
                RecoveryAction::CONFIRM_REPLACEMENT => $this->verification->handle(
                    $input->action,
                    $input,
                    $user,
                    $challenge,
                    $mode
                ),
                RecoveryAction::ACKNOWLEDGE => $this->codeLifecycle->acknowledge($input, $user, $challenge, $mode),
                RecoveryAction::CODES_UNAVAILABLE => $this->codeLifecycle->regenerate($user, $challenge, $mode),
                RecoveryAction::CANCEL => $this->codeLifecycle->cancel($user, $challenge, $mode),
            };
        } catch (RateLimitExceededException $exception) {
            return new RecoveryResult(
                RecoveryOutcome::FLOW_RATE_LIMITED,
                (string) $challenge->state,
                $mode, (string) $challenge->purpose, $user, $challenge,
                retryAfterSeconds: $exception->retryAfterSeconds
            );
        }
    }

    private function activeResult(
        RecoveryOutcome $outcome,
        object $challenge,
        User $user,
        RecoveryMode $mode
    ): RecoveryResult {
        return new RecoveryResult($outcome, (string) $challenge->state, $mode, (string) $challenge->purpose, $user, $challenge);
    }
}
