<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationChallengeService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\EmailTOTPPurpose;
use src\Business\RateLimitExceededException;
use src\Entity\User;

final class IssueRecoveryEmailVerification
{
    public function __construct(
        private readonly EmailTOTPService $emailTotp,
        private readonly EmailService $email,
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
                $this->emailPurpose($ceremony),
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

    private function emailPurpose(RecoveryCeremony $ceremony): EmailTOTPPurpose
    {
        return match ($ceremony) {
            RecoveryCeremony::ENROLLMENT => EmailTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
            RecoveryCeremony::LOST_AUTHENTICATOR => EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
            default => throw new \DomainException('The recovery ceremony does not support email verification.'),
        };
    }
}
