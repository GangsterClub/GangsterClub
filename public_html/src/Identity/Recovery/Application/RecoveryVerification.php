<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryAuthenticatorVerificationResult;
use src\Business\RecoveryFlowService;
use src\Entity\User;

final class RecoveryVerification
{
    public function __construct(
        private readonly RecoveryEmailVerification $emailVerification,
        private readonly AuthenticatorTOTPService $authenticators,
        private readonly RecoveryFlowService $recoveryFlow,
        private readonly AuthenticationChallengeService $challenges,
        private readonly RecoveryFlowState $flow,
        private readonly RecoveryRateLimitContextFactory $rateLimits,
        private readonly RecoveryCodeLifecycle $codeLifecycle
    ) {
    }

    public function handle(
        RecoveryAction $action,
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        return match ($action) {
            RecoveryAction::VERIFY_EMAIL => $this->emailVerification->verify($input, $user, $challenge, $mode),
            RecoveryAction::VERIFY_AUTHENTICATOR => $this->verifyAuthenticator($input, $user, $challenge, $mode),
            RecoveryAction::VERIFY_RECOVERY_CODE => $this->codeLifecycle->consume($input, $user, $challenge, $mode),
            RecoveryAction::CONFIRM_REPLACEMENT => $this->confirmReplacement($input, $user, $challenge, $mode),
            default => $this->result(RecoveryOutcome::INVALID_ACTION, (string) $challenge->state, $mode, $user, $challenge),
        };
    }

    private function verifyAuthenticator(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        $snapshot = $this->flow->snapshot();
        $verification = $this->recoveryFlow->verifyAuthenticator(
            $user->getId(),
            $challenge,
            $mode === RecoveryMode::LOST_AUTHENTICATOR,
            $snapshot->pendingAuthenticatorSecret,
            $this->normalizeOtp($input->authenticatorCode),
            $this->rateLimits->forChallenge($user->getId(), (int) $challenge->id),
            $snapshot->challengeToken ?? '',
            $this->flow->getChallengeBinding()
        );
        if ($verification->status === RecoveryAuthenticatorVerificationResult::INVALID) {
            return $this->result(RecoveryOutcome::AUTHENTICATOR_CODE_INVALID, (string) $challenge->state, $mode, $user, $challenge);
        }
        if ($verification->status === RecoveryAuthenticatorVerificationResult::EXPIRED) {
            return $this->result(RecoveryOutcome::CHALLENGE_EXPIRED, 'expired', $mode, $user, $challenge);
        }
        if ($verification->status === RecoveryAuthenticatorVerificationResult::WARNING_PRESENTED) {
            return $this->result(
                RecoveryOutcome::REPLACEMENT_WARNING,
                'replacement_warning_presented',
                $mode,
                $user,
                $verification->challenge ?? $challenge
            );
        }
        if ($verification->challenge === null || $verification->expectedState === null) {
            return $this->result(RecoveryOutcome::CHALLENGE_EXPIRED, 'expired', $mode, $user, $challenge);
        }

        return $this->codeLifecycle->generate(
            $user,
            $verification->challenge,
            $verification->expectedState,
            $mode
        );
    }

    private function confirmReplacement(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        if ((string) $challenge->state !== 'replacement_warning_presented'
            || $input->replacementConfirmed === false
        ) {
            return $this->result(
                RecoveryOutcome::CONFIRM_REPLACEMENT_REQUIRED,
                (string) $challenge->state,
                $mode,
                $user,
                $challenge
            );
        }

        $token = $this->flow->snapshot()->challengeToken ?? '';
        $binding = $this->flow->getChallengeBinding();
        $purpose = (string) $challenge->purpose;
        if ($mode === RecoveryMode::LOST_AUTHENTICATOR) {
            $this->flow->setPendingAuthenticatorSecret($this->authenticators->generateSecret());
            $configured = $this->challenges->transition(
                $token,
                $binding,
                $purpose,
                'replacement_warning_presented',
                'new_authenticator_configuration_presented'
            );
            $outcome = $configured === null
                ? RecoveryOutcome::CHALLENGE_EXPIRED : RecoveryOutcome::CONFIGURE_NEW_AUTHENTICATOR;
            return $this->result($outcome, 'new_authenticator_configuration_presented', $mode, $user, $configured ?? $challenge);
        }

        if ($this->challenges->isFreshReauthentication($challenge, $purpose) === false) {
            return $this->result(RecoveryOutcome::REAUTHENTICATION_EXPIRED, 'expired', $mode, $user, $challenge);
        }
        $confirmed = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'replacement_warning_presented',
            'replacement_confirmed'
        );
        if ($confirmed === null) {
            return $this->result(RecoveryOutcome::CHALLENGE_EXPIRED, 'expired', $mode, $user, $challenge);
        }
        return $this->codeLifecycle->generate($user, $confirmed, 'replacement_confirmed', $mode);
    }

    private function result(
        RecoveryOutcome $outcome,
        string $state,
        RecoveryMode $mode,
        User $user,
        object $challenge
    ): RecoveryResult {
        return new RecoveryResult($outcome, $state, $mode, (string) $challenge->purpose, $user, $challenge);
    }

    private function normalizeOtp(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }
}
