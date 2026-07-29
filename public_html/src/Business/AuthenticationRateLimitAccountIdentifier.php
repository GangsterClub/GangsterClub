<?php

declare(strict_types=1);

namespace src\Business;

final class AuthenticationRateLimitAccountIdentifier
{
    private const TYPE_USER_ID = 'user_id';
    private const TYPE_EMAIL = 'email';

    private function __construct(
        private readonly string $type,
        private readonly string $canonicalValue
    ) {
    }

    public static function forUserId(int $userId): self
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A positive user ID is required for account rate limiting.');
        }

        return new self(self::TYPE_USER_ID, (string) $userId);
    }

    public static function forEmail(string $email): self
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid email address is required for account rate limiting.');
        }

        return new self(self::TYPE_EMAIL, $normalized);
    }

    public function domainSeparatedValue(): string
    {
        return $this->type . ':' . $this->canonicalValue;
    }

    public function matchesUserId(int $userId): bool
    {
        return $this->type === self::TYPE_USER_ID
            && hash_equals($this->canonicalValue, (string) $userId);
    }
}
