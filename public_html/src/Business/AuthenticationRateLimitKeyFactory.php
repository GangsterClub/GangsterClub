<?php

declare(strict_types=1);

namespace src\Business;

final class AuthenticationRateLimitKeyFactory
{
    private const HASH_DOMAIN = 'gco-auth-rate-limit:v1';

    public function __construct(private readonly string $pepper)
    {
    }

    /** @return array<int, array{dimension: string, hash: string}> */
    public function buckets(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitAction $action,
        AuthenticationRateLimitPurpose $purpose
    ): array {
        $buckets = [];
        foreach ($context->dimensionValues() as $dimension => $canonicalIdentifier) {
            $material = implode("\0", [
                self::HASH_DOMAIN,
                'action=' . $action->value,
                'purpose=' . $purpose->value,
                'dimension=' . $dimension,
                'identifier=' . $canonicalIdentifier,
            ]);
            $buckets[] = [
                'dimension' => $dimension,
                'hash' => hash_hmac('sha256', $material, $this->pepper),
            ];
        }

        $order = array_flip([
            AuthenticationRateLimitBucketDimension::ACCOUNT->value,
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value,
            AuthenticationRateLimitBucketDimension::SESSION->value,
            AuthenticationRateLimitBucketDimension::CHALLENGE->value,
        ]);
        usort(
            $buckets,
            static fn (array $left, array $right): int =>
                $order[$left['dimension']] <=> $order[$right['dimension']]
        );

        return $buckets;
    }

    public function persistencePurpose(
        AuthenticationRateLimitAction $action,
        AuthenticationRateLimitPurpose $purpose
    ): string {
        return $action->value . '.' . $purpose->value;
    }
}
