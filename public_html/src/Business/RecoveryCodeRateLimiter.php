<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryCodeRateLimiter
{
    public function __construct(private readonly AuthenticationRateLimitService $rateLimitService)
    {
    }

    public function requireGenerationPermit(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitPurpose $purpose
    ): void {
        $permit = $this->rateLimitService->consumeAttempt(
            $context,
            AuthenticationRateLimitAction::RECOVERY_CODE_GENERATE,
            $purpose,
            $this->generationPolicies()
        );
        if ($permit->allowed === false) {
            throw new RateLimitExceededException(
                AuthenticationRateLimitAction::RECOVERY_CODE_GENERATE->value,
                $permit->retryAfterSeconds ?? 3600
            );
        }
    }

    public function verificationPermit(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitPurpose $purpose
    ): RateLimitDecision {
        return $this->rateLimitService->consumeAttempt(
            $context,
            AuthenticationRateLimitAction::RECOVERY_CODE_VERIFY,
            $purpose,
            $this->verificationPolicies()
        );
    }

    public function resetVerification(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitPurpose $purpose
    ): void {
        $this->rateLimitService->resetAfterSuccessfulCredentialVerification($context, $purpose);
    }

    /** @return array<string, RateLimitPolicy> */
    private function verificationPolicies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value => new RateLimitPolicy(5, 900, 900),
            AuthenticationRateLimitBucketDimension::CHALLENGE->value => new RateLimitPolicy(5, 900, 900),
            AuthenticationRateLimitBucketDimension::SESSION->value => new RateLimitPolicy(15, 900, 900),
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new RateLimitPolicy(50, 900, 900),
        ];
    }

    /** @return array<string, RateLimitPolicy> */
    private function generationPolicies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value => new RateLimitPolicy(3, 3600, 3600),
            AuthenticationRateLimitBucketDimension::CHALLENGE->value => new RateLimitPolicy(3, 3600, 3600),
            AuthenticationRateLimitBucketDimension::SESSION->value => new RateLimitPolicy(6, 3600, 3600),
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new RateLimitPolicy(30, 3600, 3600),
        ];
    }
}
