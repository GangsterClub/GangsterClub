<?php

declare(strict_types=1);

namespace app\Service;

use DateInterval;
use DateTimeImmutable;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * Lightweight wrapper around firebase/php-jwt with sane defaults for the app.
 */
class JWT
{
    /**
     * Default time (in seconds) before a token expires.
     */
    public const DEFAULT_TTL = 360;

    /**
     * Time (in seconds) before expiry when a token should be refreshed.
     */
    public const REFRESH_THRESHOLD = 60;

    /**
     * Clock skew (in seconds) tolerated when validating tokens.
     */
    public const CLOCK_SKEW = 5;

    /**
     * Summary of __construct
     */
    public function __construct(
        private readonly string $secret = JWT_SECRET,
        private readonly string $issuer = APP_DOMAIN,
        private readonly string $algorithm = 'HS512',
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
    }

    /**
     * Issue a new signed JWT token.
     *
     * @param string $userName Subject of the token (login email in this app).
     * @param array<string, mixed> $extraClaims Additional claims to embed.
     * @param int|null $ttl Token lifetime in seconds.
     */
    public function issue(string $userName, array $extraClaims = [], ?int $ttl = null): string
    {
        $issuedAt = new DateTimeImmutable();
        $expiration = $ttl !== null
            ? $issuedAt->add(new DateInterval(sprintf('PT%dS', $ttl)))
            : $issuedAt->add(new DateInterval(sprintf('PT%dS', $this->ttl)));

        $claims = array_merge(
            $extraClaims,
            [
                'iat' => $issuedAt->getTimestamp(),
                'iss' => $this->issuer,
                'nbf' => $issuedAt->getTimestamp(),
                'exp' => $expiration->getTimestamp(),
                'userName' => $userName,
            ]
        );

        return FirebaseJWT::encode($claims, $this->secret, $this->algorithm);
    }

    /**
     * Decode and verify the JWT signature.
     *
     * @throws ExpiredException
     * @throws SignatureInvalidException
     * @throws BeforeValidException
     * @throws UnexpectedValueException
     */
    public function decode(string $token, int $leeway = self::CLOCK_SKEW): object
    {
        $previousLeeway = FirebaseJWT::$leeway;
        FirebaseJWT::$leeway = $leeway;

        try {
            return FirebaseJWT::decode($token, new Key($this->secret, $this->algorithm));
        } finally {
            FirebaseJWT::$leeway = $previousLeeway;
        }
    }

    /**
     * Validate application-specific claims.
     *
     * @throws ExpiredException
     * @throws BeforeValidException
     * @throws UnexpectedValueException
     */
    public function validateClaims(object $payload, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        if (!isset($payload->iss) || $payload->iss !== $this->issuer) {
            throw new UnexpectedValueException('Invalid token issuer.');
        }

        if (isset($payload->nbf) && $payload->nbf > $now->getTimestamp()) {
            throw new BeforeValidException('Token cannot yet be used.');
        }

        if (!isset($payload->exp) || $payload->exp < $now->getTimestamp()) {
            throw new ExpiredException('Token has expired.');
        }
    }

    /**
     * Determine whether the token should be refreshed soon.
     */
    public function shouldRefresh(object $payload, ?DateTimeImmutable $now = null, int $threshold = self::REFRESH_THRESHOLD): bool
    {
        if (!isset($payload->exp)) {
            return false;
        }

        $now ??= new DateTimeImmutable();

        return ($payload->exp - $now->getTimestamp()) <= $threshold;
    }

    /**
     * Refresh an existing token using its payload.
     */
    public function refresh(object $payload, array $extraClaims = []): string
    {
        $userName = $payload->userName ?? '';
        if ($userName === '') {
            throw new UnexpectedValueException('Token payload is missing the "userName" claim.');
        }

        return $this->issue($userName, $extraClaims);
    }
}
