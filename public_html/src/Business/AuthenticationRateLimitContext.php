<?php

declare(strict_types=1);

namespace src\Business;

final class AuthenticationRateLimitContext
{
    private function __construct(
        public readonly AuthenticationRateLimitAccountIdentifier $account,
        private readonly string $ipAddress,
        private readonly ?string $sessionIdentifier,
        private readonly ?string $challengeIdentifier
    ) {
    }

    public static function forUser(
        int $userId,
        string $ipAddress,
        ?string $sessionIdentifier = null,
        ?string $challengeIdentifier = null
    ): self {
        return new self(
            AuthenticationRateLimitAccountIdentifier::forUserId($userId),
            self::normalizeIpAddress($ipAddress),
            self::normalizeOptionalIdentifier($sessionIdentifier, 'session'),
            self::normalizeOptionalIdentifier($challengeIdentifier, 'challenge')
        );
    }

    public static function forEmail(
        string $email,
        string $ipAddress,
        ?string $sessionIdentifier = null,
        ?string $challengeIdentifier = null
    ): self {
        return new self(
            AuthenticationRateLimitAccountIdentifier::forEmail($email),
            self::normalizeIpAddress($ipAddress),
            self::normalizeOptionalIdentifier($sessionIdentifier, 'session'),
            self::normalizeOptionalIdentifier($challengeIdentifier, 'challenge')
        );
    }

    /** @return array<string, string> */
    public function dimensionValues(): array
    {
        $values = [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value => $this->account->domainSeparatedValue(),
            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value => $this->ipAddress,
        ];

        if ($this->sessionIdentifier !== null) {
            $values[AuthenticationRateLimitBucketDimension::SESSION->value] = $this->sessionIdentifier;
        }
        if ($this->challengeIdentifier !== null) {
            $values[AuthenticationRateLimitBucketDimension::CHALLENGE->value] = $this->challengeIdentifier;
        }

        return $values;
    }

    public function matchesUserId(int $userId): bool
    {
        return $this->account->matchesUserId($userId);
    }

    private static function normalizeIpAddress(string $ipAddress): string
    {
        $packed = @inet_pton(trim($ipAddress));
        if ($packed === false) {
            throw new \InvalidArgumentException('A valid IP address is required for authentication rate limiting.');
        }

        $normalized = inet_ntop($packed);
        if ($normalized === false) {
            throw new \InvalidArgumentException('Unable to canonicalize the authentication IP address.');
        }

        return $normalized;
    }

    private static function normalizeOptionalIdentifier(?string $identifier, string $label): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $identifier = trim($identifier);
        if ($identifier === '' || strlen($identifier) > 512) {
            throw new \InvalidArgumentException('A non-empty bounded ' . $label . ' identifier is required.');
        }

        return $identifier;
    }
}
