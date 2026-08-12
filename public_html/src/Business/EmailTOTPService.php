<?php

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\EmailTOTPRepository;

class EmailTOTPService
{
    public function __construct(
        protected TOTPService $totp,
        protected EmailTOTPRepository $emailTotpRepository,
        protected AuthenticationRateLimitService $rateLimitService
    ) {
    }

    public function issue(int $userId, EmailTOTPPurpose $purpose, AuthenticationRateLimitContext $context): IssuedEmailTOTP
    {
        $this->assertContext($userId, $context);
        $this->requirePermit($context, AuthenticationRateLimitAction::EMAIL_TOTP_ISSUE, $purpose, $this->issuancePolicies());
        $secret = $this->totp->generateSecret(TOTP_DIGITS, TOTP_PERIOD);
        $id = $this->emailTotpRepository->storeTOTP(
            $userId,
            $purpose,
            $secret,
            date('Y-m-d H:i:s', time() + TOTP_PERIOD)
        );

        return new IssuedEmailTOTP($id, $this->totp->generateTOTP($secret, TOTP_DIGITS, TOTP_PERIOD));
    }

    public function verify(
        int $userId,
        EmailTOTPPurpose $purpose,
        string $submittedCode,
        AuthenticationRateLimitContext $context
    ): bool {
        $this->assertContext($userId, $context);
        $this->requirePermit($context, AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY, $purpose, $this->verificationPolicies());
        foreach ($this->emailTotpRepository->findAllValidTOTPs($userId, $purpose) as $candidate) {
            if ($this->matches($candidate, $submittedCode) === false) {
                continue;
            }
            return $this->consumeMatch($candidate, $userId, $purpose, $context);
        }

        return false;
    }

    public function cancelIssued(IssuedEmailTOTP $issued): void
    {
        $this->emailTotpRepository->deleteTOTP($issued->id);
    }

    private function consumeMatch(
        object $candidate,
        int $userId,
        EmailTOTPPurpose $purpose,
        AuthenticationRateLimitContext $context
    ): bool {
        $consumed = $this->emailTotpRepository->consumeTOTP((int) $candidate->id, $userId, $purpose);
        if ($consumed === true) {
            $this->rateLimitService->resetAfterSuccessfulVerification(
                $context,
                AuthenticationRateLimitAction::EMAIL_TOTP_VERIFY,
                $purpose->rateLimitPurpose()
            );
        }
        return $consumed;
    }

    private function matches(object $candidate, string $submittedCode): bool
    {
        if (strtotime((string) $candidate->expires_at) < time()) {
            return false;
        }
        return $this->totp->verifyTOTP(
            (string) $candidate->totp_secret,
            $submittedCode,
            TOTP_DIGITS,
            TOTP_PERIOD
        ) === true;
    }

    /** @param array<string, RateLimitPolicy> $policies */
    private function requirePermit(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitAction $action,
        EmailTOTPPurpose $purpose,
        array $policies
    ): void {
        $decision = $this->rateLimitService->consumeAttempt(
            $context,
            $action,
            $purpose->rateLimitPurpose(),
            $policies
        );
        if ($decision->allowed === false) {
            throw new RateLimitExceededException($action->value, $decision->retryAfterSeconds ?? 60);
        }
    }

    private function assertContext(int $userId, AuthenticationRateLimitContext $context): void
    {
        if ($userId <= 0 || $context->matchesUserId($userId) === false) {
            throw new \InvalidArgumentException('The Email TOTP owner must match the rate-limit account identity.');
        }
    }

    /** @return array<string, RateLimitPolicy> */
    private function issuancePolicies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value =>
                new RateLimitPolicy(3, 600, 60),

            AuthenticationRateLimitBucketDimension::CHALLENGE->value =>
                new RateLimitPolicy(3, 600, 60),

            AuthenticationRateLimitBucketDimension::SESSION->value =>
                new RateLimitPolicy(6, 600, 60),

            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value =>
                new RateLimitPolicy(20, 600, 60),
        ];
    }

    /** @return array<string, RateLimitPolicy> */
    private function verificationPolicies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value =>
                new RateLimitPolicy(5, 900, 60),

            AuthenticationRateLimitBucketDimension::CHALLENGE->value =>
                new RateLimitPolicy(5, 900, 60),

            AuthenticationRateLimitBucketDimension::SESSION->value =>
                new RateLimitPolicy(10, 900, 60),

            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value =>
                new RateLimitPolicy(50, 900, 60),
        ];
    }
}
