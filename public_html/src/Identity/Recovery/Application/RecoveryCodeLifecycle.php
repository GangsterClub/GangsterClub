<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\EmailService;
use src\Business\RecoveryCodeConsumptionResult;
use src\Business\RecoveryCodeGenerationResult;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Entity\User;

final class RecoveryCodeLifecycle
{
    private const PRESENTED_STATES_BY_PURPOSE = [
        RecoveryCeremony::ENROLLMENT->value => 'recovery_codes_presented_unacknowledged',
        RecoveryCeremony::INITIAL_CODES->value => 'recovery_codes_presented_unacknowledged',
        RecoveryCeremony::REPLACEMENT_CODES->value => 'replacement_codes_presented_unacknowledged',
        RecoveryCeremony::LOST_AUTHENTICATOR->value => 'new_recovery_codes_presented_unacknowledged',
    ];

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

    public function consume(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        if ((string) $challenge->state !== 'recovery_code_pending') {
            return new RecoveryResult(
                RecoveryOutcome::INVALID_ACTION,
                (string) $challenge->state,
                $mode,
                (string) $challenge->purpose,
                $user,
                $challenge
            );
        }

        $consumption = $this->recoveryCodes->consumeActiveCode(
            $user->getId(),
            $input->recoveryCode,
            $this->rateLimits->forChallenge($user->getId(), (int) $challenge->id),
            AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY
        );
        if ($consumption->status === RecoveryCodeConsumptionResult::STATUS_RATE_LIMITED) {
            return new RecoveryResult(
                RecoveryOutcome::FLOW_RATE_LIMITED,
                'recovery_code_pending',
                $mode,
                (string) $challenge->purpose,
                $user,
                $challenge,
                retryAfterSeconds: $consumption->retryAfterSeconds
            );
        }
        if ($consumption->isConsumed() === false) {
            return new RecoveryResult(
                RecoveryOutcome::RECOVERY_CODE_INVALID,
                'recovery_code_pending',
                $mode,
                (string) $challenge->purpose,
                $user,
                $challenge
            );
        }

        $token = $this->flow->snapshot()->challengeToken ?? '';
        $binding = $this->flow->getChallengeBinding();
        $purpose = RecoveryCeremony::LOST_AUTHENTICATOR->value;
        $consumed = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'recovery_code_pending',
            'recovery_code_consumed'
        );
        $warning = $consumed === null ? null : $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'recovery_code_consumed',
            'replacement_warning_presented'
        );
        if ($warning === null) {
            return new RecoveryResult(
                RecoveryOutcome::CHALLENGE_EXPIRED,
                'expired',
                $mode,
                (string) $challenge->purpose,
                $user,
                $challenge
            );
        }
        $this->email->sendSecurityNotification(
            $user->getEmail(),
            'Recovery code used',
            'A recovery code was used to begin replacing a lost authenticator. If this was not you, secure your account immediately.'
        );
        return new RecoveryResult(
            RecoveryOutcome::LOST_REPLACEMENT_WARNING,
            'replacement_warning_presented',
            $mode,
            (string) $warning->purpose,
            $user,
            $warning,
            remainingCount: $consumption->remainingCount
        );
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

        $generated = $this->createPendingSet($user, $challenge, $mode);

        $presentedState = self::PRESENTED_STATES_BY_PURPOSE[$purpose]
            ?? 'recovery_codes_presented_unacknowledged';
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
        if ($pendingSetId === null
            || in_array((string) $challenge->state, self::PRESENTED_STATES_BY_PURPOSE, true) === false
        ) {
            return $this->result(RecoveryOutcome::INVALID_ACTION, (string) $challenge->state, $mode, $challenge);
        }

        $this->recoveryCodes->invalidatePendingSet($user->getId(), $pendingSetId, 'unacknowledged_display');
        $this->flow->setPendingRecoverySetId(null);
        $generated = $this->createPendingSet($user, $challenge, $mode);
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

    private function createPendingSet(
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryCodeGenerationResult
    {
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

        return $generated;
    }
}
