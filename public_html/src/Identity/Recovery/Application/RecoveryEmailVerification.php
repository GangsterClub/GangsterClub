<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\EmailTOTPPurpose;
use src\Business\RateLimitExceededException;
use src\Entity\User;

final class RecoveryEmailVerification
{
    public function __construct(
        private readonly EmailTOTPService $emailTotp,
        private readonly EmailService $email,
        private readonly AuthenticatorTOTPService $authenticators,
        private readonly AuthenticationChallengeService $challenges,
        private readonly RecoveryFlowState $flow,
        private readonly RecoveryRateLimitContextFactory $rateLimits
    ) {
    }

    public function issue(
        User $user,
        RecoveryCeremony $ceremony,
        RecoveryMode $mode,
        string $challengeToken
    ): RecoveryResult {
        try {
            $issued = $this->emailTotp->issue(
                $user->getId(),
                $this->emailPurpose(
                    $ceremony->value,
                    'The recovery ceremony does not support email verification.'
                ),
                $this->rateLimits->forToken($user->getId(), $challengeToken)
            );
        } catch (RateLimitExceededException $exception) {
            $this->flow->clear();
            return new RecoveryResult(
                RecoveryOutcome::START_RATE_LIMITED,
                'rate_limited', $mode, $ceremony->value,
                retryAfterSeconds: $exception->retryAfterSeconds
            );
        }

        if ($this->email->sendEmailTOTP($user->getEmail(), $issued->code) === false) {
            $this->emailTotp->cancelIssued($issued);
            $this->challenges->cancel(
                $challengeToken,
                $this->flow->getChallengeBinding(),
                $ceremony->value
            );
            $this->flow->clear();
            return new RecoveryResult(RecoveryOutcome::EMAIL_SEND_FAILED, 'send_failed', $mode, $ceremony->value);
        }

        return new RecoveryResult(RecoveryOutcome::EMAIL_VERIFICATION_PENDING, 'email_verification_pending', $mode, $ceremony->value);
    }

    public function verify(
        RecoveryActionInput $input,
        User $user,
        object $challenge,
        RecoveryMode $mode
    ): RecoveryResult {
        if ((string) $challenge->state !== 'email_verification_pending'
            || $this->emailTotp->verify(
                $user->getId(),
                $this->emailPurpose(
                    (string) $challenge->purpose,
                    'The challenge does not support email verification.'
                ),
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

    private function emailPurpose(string $purpose, string $unsupportedMessage): EmailTOTPPurpose
    {
        return match ($purpose) {
            RecoveryCeremony::ENROLLMENT->value => EmailTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
            RecoveryCeremony::LOST_AUTHENTICATOR->value => EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
            default => throw new \DomainException($unsupportedMessage),
        };
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
