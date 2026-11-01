<?php

declare(strict_types=1);

namespace src\Business;

final class AuthenticationRateLimitPolicies
{
    /** @return array<string, RateLimitPolicy> */
    public static function emailIssuance(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value => new RateLimitPolicy(3, 600, 60),
            AuthenticationRateLimitBucketDimension::CHALLENGE->value => new RateLimitPolicy(3, 600, 60),
            AuthenticationRateLimitBucketDimension::SESSION->value => new RateLimitPolicy(6, 600, 60),
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new RateLimitPolicy(20, 600, 60),
        ];
    }

    /** @return array<string, RateLimitPolicy> */
    public static function emailVerification(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value => new RateLimitPolicy(5, 900, 60),
            AuthenticationRateLimitBucketDimension::CHALLENGE->value => new RateLimitPolicy(5, 900, 60),
            AuthenticationRateLimitBucketDimension::SESSION->value => new RateLimitPolicy(10, 900, 60),
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => new RateLimitPolicy(50, 900, 60),
        ];
    }
}
