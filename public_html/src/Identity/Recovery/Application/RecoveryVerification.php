<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\EmailTOTPPurpose;
use src\Business\RecoveryAuthenticatorVerificationResult;
use src\Business\RecoveryCodeConsumptionResult;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFlowService;
use src\Entity\User;

final class RecoveryVerification
{
    public function __construct(
        private readonly EmailTOTPService $emailTotp,
        private readonly AuthenticatorTOTPService $authenticators,
        private readonly RecoveryFlowService $recoveryFlow,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly AuthenticationChallengeService $challenges,
        private readonly EmailService $email,
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
            RecoveryAction::VERIFY_EMAIL => $this->verifyEmail($input, $user, $challenge, $mode),
            RecoveryAction::VERIFY_AUTHENTICATOR => $this->verifyAuthenticator($input, $user, $challenge, $mode),
            RecoveryAction::VERIFY_RECOVERY_CODE => $this->verifyRecoveryCode($input, $user, $challenge, $mode),
            RecoveryAction::CONFIRM_REPLACEMENT => $this->confirmReplacement($input, $user, $challenge, $mode),
            default => $this->result(RecoveryOutcome::INVALID_ACTION, (string) $challenge->state, $mode, $user, $challenge),
        };
    }

    private function verifyEmail(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        if ((string) $challenge->state !== 'email_verification_pending'
            || $this->emailTotp->verify(
                $user->getId(),
                $this->emailPurpose((string) $challenge->purpose),
                $this->normalizeOtp($input->emailCode),
                $this->rateLimits->forChallenge($user->getId(), (int) $challenge->id)
            ) === false
        ) {
            return $this->result(RecoveryOutcome::EMAIL_CODE_INVALID, (string) $challenge->state, $mode, $user, $challenge);
        }

        $purpose = (string) $challenge->purpose;
        $token = $this->flow->snapshot()->challengeToken ?? '';
        $binding = $this->flow->getChallengeBinding();
        $verified = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'email_verification_pending',
            'email_verified'
        );
        if ($verified === null) {
            return $this->result(RecoveryOutcome::CHALLENGE_EXPIRED, 'expired', $mode, $user, $challenge);
        }

        if ($mode === RecoveryMode::LOST_AUTHENTICATOR) {
            $pending = $this->challenges->transition(
                $token,
                $binding,
                $purpose,
                'email_verified',
                'recovery_code_pending'
            );
            $outcome = $pending === null ? RecoveryOutcome::CHALLENGE_EXPIRED : RecoveryOutcome::ENTER_RECOVERY_CODE;
            return $this->result($outcome, 'recovery_code_pending', $mode, $user, $pending ?? $verified);
        }

        $this->flow->setPendingAuthenticatorSecret($this->authenticators->generateSecret());
        $configured = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'email_verified',
            'authenticator_configuration_presented'
        );
        $outcome = $configured === null ? RecoveryOutcome::CHALLENGE_EXPIRED : RecoveryOutcome::CONFIGURE_AUTHENTICATOR;
        return $this->result($outcome, 'authenticator_configuration_presented', $mode, $user, $configured ?? $verified);
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

    private function verifyRecoveryCode(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        if ((string) $challenge->state !== 'recovery_code_pending') {
            return $this->result(RecoveryOutcome::INVALID_ACTION, (string) $challenge->state, $mode, $user, $challenge);
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
            return $this->result(RecoveryOutcome::RECOVERY_CODE_INVALID, 'recovery_code_pending', $mode, $user, $challenge);
        }

        $warning = $this->advanceToReplacementWarning($challenge);
        if ($warning === null) {
            return $this->result(RecoveryOutcome::CHALLENGE_EXPIRED, 'expired', $mode, $user, $challenge);
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

    private function advanceToReplacementWarning(object $challenge): ?object
    {
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
        return $consumed === null ? null : $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'recovery_code_consumed',
            'replacement_warning_presented'
        );
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

    private function emailPurpose(string $purpose): EmailTOTPPurpose
    {
        return match ($purpose) {
            RecoveryCeremony::ENROLLMENT->value => EmailTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
            RecoveryCeremony::LOST_AUTHENTICATOR->value => EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
            default => throw new \DomainException('The challenge does not support email verification.'),
        };
    }

    private function normalizeOtp(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }
}
