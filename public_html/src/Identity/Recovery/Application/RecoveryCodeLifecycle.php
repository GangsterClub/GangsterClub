<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\EmailService;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Entity\User;

final class RecoveryCodeLifecycle
{
    public function __construct(
        private readonly AuthenticationChallengeService $challenges,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly RecoveryFeatureService $recovery,
        private readonly RecoveryFlowState $flow,
        private readonly RecoveryIdentityContext $identity,
        private readonly RecoveryRateLimitContextFactory $rateLimits,
        private readonly EmailService $email
    ) {
    }

    public function generate(
        User $user,
        object $challenge,
        string $expectedState,
        RecoveryMode $mode
    ): RecoveryResult {
        $purpose = (string) $challenge->purpose;
        if ($this->requiresFreshReauthentication($purpose) === true
            && $this->challenges->isFreshReauthentication($challenge, $purpose) === false
        ) {
            return $this->result(RecoveryOutcome::REAUTHENTICATION_EXPIRED, 'expired', $mode, $challenge);
        }

        $generation = (int) ($challenge->baseline_authenticator_generation ?? 1);
        if ($mode === RecoveryMode::LOST_AUTHENTICATOR) {
            ++$generation;
        }
        $replacesSetId = $challenge->baseline_recovery_code_set_id === null
            ? null : (int) $challenge->baseline_recovery_code_set_id;
        $generated = $this->recoveryCodes->generatePendingSet(
            $user->getId(),
            AuthenticationRateLimitPurpose::from($purpose),
            $this->rateLimits->forChallenge($user->getId(), (int) $challenge->id),
            $generation,
            (int) $challenge->id,
            $replacesSetId
        );
        $this->flow->setPendingRecoverySetId($generated->setId);

        $presentedState = $this->presentedState($purpose);
        $snapshot = $this->flow->snapshot();
        $presented = $this->challenges->transition(
            $snapshot->challengeToken ?? '',
            $this->flow->getChallengeBinding(),
            $purpose,
            $expectedState,
            $presentedState
        );
        if ($presented === null) {
            $this->recoveryCodes->invalidatePendingSet(
                $user->getId(),
                $generated->setId,
                'challenge_transition_failed'
            );
            return $this->result(RecoveryOutcome::CHALLENGE_EXPIRED, 'expired', $mode, $challenge);
        }

        $this->flow->markRecoveryCodesDelivered($generated->setId);
        return new RecoveryResult(
            RecoveryOutcome::CODES_GENERATED,
            $presentedState,
            $mode,
            $purpose,
            $user,
            $presented,
            $generated->codes,
            count($generated->codes)
        );
    }

    public function acknowledge(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        if ($input->codesSaved === false) {
            return $this->result(RecoveryOutcome::ACKNOWLEDGEMENT_REQUIRED, (string) $challenge->state, $mode, $challenge);
        }

        $snapshot = $this->flow->snapshot();
        $setId = $snapshot->pendingRecoverySetId;
        if ($setId === null || $snapshot->deliveredRecoverySetId !== $setId) {
            return $this->result(RecoveryOutcome::CODES_DISPLAY_UNAVAILABLE, (string) $challenge->state, $mode, $challenge);
        }

        $purpose = (string) $challenge->purpose;
        $lostFlow = $mode === RecoveryMode::LOST_AUTHENTICATOR;
        $completion = $this->recovery->completeAcknowledgedFlow(
            $user->getId(),
            $setId,
            $challenge,
            $snapshot->pendingAuthenticatorSecret,
            $snapshot->challengeToken ?? '',
            $this->flow->getChallengeBinding(),
            $lostFlow
        );
        if ($completion === RecoveryFeatureService::COMPLETION_REAUTHENTICATION_EXPIRED) {
            return $this->result(RecoveryOutcome::REAUTHENTICATION_EXPIRED, 'expired', $mode, $challenge);
        }
        if ($completion === RecoveryFeatureService::COMPLETION_FAILED) {
            return $this->result(RecoveryOutcome::ACTIVATION_FAILED, (string) $challenge->state, $mode, $challenge);
        }

        if ($lostFlow === true) {
            $this->identity->login($user->getId());
        }
        $this->flow->clear();
        $this->identity->rotateCsrfToken();
        $this->email->sendRecoveryCompletionNotification($user->getEmail(), $purpose, $lostFlow);

        $outcome = $purpose === RecoveryCeremony::REPLACEMENT_CODES->value
            ? RecoveryOutcome::PREVIOUS_CODES_INVALID : RecoveryOutcome::COMPLETED;
        return new RecoveryResult($outcome, 'completed', $mode, $purpose, $user);
    }

    public function regenerate(User $user, object $challenge, RecoveryMode $mode): RecoveryResult
    {
        $pendingSetId = $this->flow->snapshot()->pendingRecoverySetId;
        if ($pendingSetId === null || $this->isPresentedState((string) $challenge->state) === false) {
            return $this->result(RecoveryOutcome::INVALID_ACTION, (string) $challenge->state, $mode, $challenge);
        }

        $this->recoveryCodes->invalidatePendingSet($user->getId(), $pendingSetId, 'unacknowledged_display');
        $this->flow->setPendingRecoverySetId(null);
        $generation = (int) ($challenge->baseline_authenticator_generation ?? 1)
            + ($mode === RecoveryMode::LOST_AUTHENTICATOR ? 1 : 0);
        $replacesSetId = $challenge->baseline_recovery_code_set_id === null
            ? null : (int) $challenge->baseline_recovery_code_set_id;
        $generated = $this->recoveryCodes->generatePendingSet(
            $user->getId(),
            AuthenticationRateLimitPurpose::from((string) $challenge->purpose),
            $this->rateLimits->forChallenge($user->getId(), (int) $challenge->id),
            $generation,
            (int) $challenge->id,
            $replacesSetId
        );
        $this->flow->setPendingRecoverySetId($generated->setId);
        $this->flow->markRecoveryCodesDelivered($generated->setId);

        return new RecoveryResult(
            RecoveryOutcome::CODES_REGENERATED,
            (string) $challenge->state,
            $mode,
            (string) $challenge->purpose,
            $user,
            $challenge,
            $generated->codes,
            count($generated->codes)
        );
    }

    public function cancel(User $user, object $challenge, RecoveryMode $mode): RecoveryResult
    {
        $snapshot = $this->flow->snapshot();
        $setId = $snapshot->pendingRecoverySetId;
        if ($setId !== null) {
            $this->recoveryCodes->invalidatePendingSet($user->getId(), $setId, 'flow_cancelled');
        }
        $this->challenges->cancel(
            $snapshot->challengeToken ?? '',
            $this->flow->getChallengeBinding(),
            (string) $challenge->purpose
        );
        $this->flow->clear();
        return $this->result(RecoveryOutcome::CANCELLED, 'cancelled', $mode, $challenge);
    }

    private function result(
        RecoveryOutcome $outcome,
        string $state,
        RecoveryMode $mode,
        object $challenge
    ): RecoveryResult {
        return new RecoveryResult($outcome, $state, $mode, (string) $challenge->purpose, challenge: $challenge);
    }

    private function requiresFreshReauthentication(string $purpose): bool
    {
        return in_array($purpose, [
            RecoveryCeremony::INITIAL_CODES->value,
            RecoveryCeremony::REPLACEMENT_CODES->value,
        ], true);
    }

    private function presentedState(string $purpose): string
    {
        return match ($purpose) {
            RecoveryCeremony::REPLACEMENT_CODES->value => 'replacement_codes_presented_unacknowledged',
            RecoveryCeremony::LOST_AUTHENTICATOR->value => 'new_recovery_codes_presented_unacknowledged',
            default => 'recovery_codes_presented_unacknowledged',
        };
    }

    private function isPresentedState(string $state): bool
    {
        return in_array($state, [
            'recovery_codes_presented_unacknowledged',
            'replacement_codes_presented_unacknowledged',
            'new_recovery_codes_presented_unacknowledged',
        ], true);
    }
}
