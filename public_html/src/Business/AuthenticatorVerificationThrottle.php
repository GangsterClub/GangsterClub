<?php

declare(strict_types=1);

namespace src\Business;

final class AuthenticatorVerificationThrottle
{
    public function __construct(private readonly AuthenticationRateLimitService $rateLimitService)
    {
    }

    public function requirePermit(
        int $userId,
        AuthenticationRateLimitContext $context,
        AuthenticatorTOTPPurpose $purpose
    ): void {
        if ($userId <= 0 || $context->matchesUserId($userId) === false) {
            throw new \InvalidArgumentException(
                'The authenticator owner must match the rate-limit account identity.'
            );
        }

        $decision = $this->rateLimitService->consumeAttempt(
            $context,
            AuthenticationRateLimitAction::AUTHENTICATOR_TOTP_VERIFY,
            $purpose->rateLimitPurpose(),
            $this->policies()
        );
        if ($decision->allowed === false) {
            throw new RateLimitExceededException(
                AuthenticationRateLimitAction::AUTHENTICATOR_TOTP_VERIFY->value,
                $decision->retryAfterSeconds ?? 60
            );
        }
    }

    public function reset(
        AuthenticationRateLimitContext $context,
        AuthenticatorTOTPPurpose $purpose
    ): void {
        $this->rateLimitService->resetAfterSuccessfulVerification(
            $context,
            AuthenticationRateLimitAction::AUTHENTICATOR_TOTP_VERIFY,
            $purpose->rateLimitPurpose()
        );
    }

    /** @return array<string, RateLimitPolicy> */
    private function policies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value => new RateLimitPolicy(5, 900, 60),
            AuthenticationRateLimitBucketDimension::CHALLENGE->value => new RateLimitPolicy(5, 900, 60),
            AuthenticationRateLimitBucketDimension::SESSION->value => new RateLimitPolicy(10, 900, 60),
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new RateLimitPolicy(50, 900, 60),
        ];
    }
}
