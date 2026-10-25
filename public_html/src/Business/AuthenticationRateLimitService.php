<?php

declare(strict_types=1);

namespace src\Business;

use src\Data\Connection;
use src\Data\Repository\AuthenticationRateLimitRepository;

class AuthenticationRateLimitService
{
    private const MAX_TRANSACTION_ATTEMPTS = 3;
    private const MAX_BACKOFF_MULTIPLIER = 16;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuthenticationRateLimitRepository $repository,
        private readonly string $pepper,
        private readonly ?\Closure $clock = null,
        ?AuthenticationRateLimitKeyFactory $keyFactory = null,
        ?DatabaseRetryClassifier $retryClassifier = null
    ) {
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('The rate-limit pepper must contain at least 32 bytes.');
        }
        $this->keyFactory = $keyFactory ?? new AuthenticationRateLimitKeyFactory($pepper);
        $this->retryClassifier = $retryClassifier ?? new DatabaseRetryClassifier();
    }

    private readonly AuthenticationRateLimitKeyFactory $keyFactory;
    private readonly DatabaseRetryClassifier $retryClassifier;

    /**
     * @param array<string, RateLimitPolicy> $policies
     */
    public function consumeAttempt(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitAction $action,
        AuthenticationRateLimitPurpose $purpose,
        array $policies
    ): RateLimitDecision {
        $buckets = $this->keyFactory->buckets($context, $action, $purpose);
        $persistencePurpose = $this->keyFactory->persistencePurpose($action, $purpose);

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; ++$attempt) {
            try {
                return $this->consumeBuckets(
                    $buckets,
                    $persistencePurpose,
                    $policies
                );
            } catch (\PDOException $exception) {
                $retryable = $this->retryClassifier->isUniqueConstraintViolation($exception)
                    || $this->retryClassifier->isRetryableTransactionFailure($exception);

                if ($attempt >= self::MAX_TRANSACTION_ATTEMPTS || $retryable === false) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException(
            'Unable to record the authentication rate-limit attempt.'
        );
    }

    public function resetAfterSuccessfulCredentialVerification(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitPurpose $purpose
    ): void {
        $this->resetAfterSuccessfulVerification($context, AuthenticationRateLimitAction::RECOVERY_CODE_VERIFY, $purpose);
    }

    public function resetAfterSuccessfulVerification(
        AuthenticationRateLimitContext $context,
        AuthenticationRateLimitAction $action,
        AuthenticationRateLimitPurpose $purpose
    ): void {
        $buckets = $this->keyFactory->buckets($context, $action, $purpose);
        $persistencePurpose = $this->keyFactory->persistencePurpose($action, $purpose);
        $resetDimensions = [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value,
            AuthenticationRateLimitBucketDimension::SESSION->value,
            AuthenticationRateLimitBucketDimension::CHALLENGE->value,
        ];

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; ++$attempt) {
            try {
                $this->connection->transaction(function () use (
                    $buckets,
                    $persistencePurpose,
                    $resetDimensions
                ): void {
                    foreach ($buckets as $bucket) {
                        if (in_array($bucket['dimension'], $resetDimensions, true) === true) {
                            $this->repository->delete($bucket['hash'], $persistencePurpose);
                        }
                    }
                });
                return;
            } catch (\PDOException $exception) {
                if ($attempt >= self::MAX_TRANSACTION_ATTEMPTS
                    || $this->retryClassifier->isRetryableTransactionFailure($exception) === false
                ) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to reset authentication rate-limit buckets.');
    }

    /**
     * @param array<int, array{dimension: string, hash: string}> $buckets
     * @param array<string, RateLimitPolicy> $policies
     */
    private function consumeBuckets(
        array $buckets,
        string $persistencePurpose,
        array $policies
    ): RateLimitDecision {
        return $this->connection->transaction(function () use (
            $buckets,
            $persistencePurpose,
            $policies
        ): RateLimitDecision {
            $now = $this->now();
            $remainingAttempts = PHP_INT_MAX;
            $retryAfterSeconds = null;
            $blockedDimensions = [];

            foreach ($buckets as $bucket) {
                $dimension = $bucket['dimension'];
                $policy = $policies[$dimension] ?? null;

                if (($policy instanceof RateLimitPolicy) === false) {
                    throw new \InvalidArgumentException(
                        'Missing rate-limit policy for dimension: ' . $dimension
                    );
                }

                $record = $this->repository->findForUpdate(
                    $bucket['hash'],
                    $persistencePurpose
                );

                $bucketDecision = $this->recordBucketAttempt(
                    $bucket['hash'],
                    $persistencePurpose,
                    $dimension,
                    $record,
                    $policy,
                    $now
                );

                $remainingAttempts = min(
                    $remainingAttempts,
                    $bucketDecision->remainingAttempts
                );

                if ($bucketDecision->allowed === false) {
                    $blockedDimensions[] = $dimension;
                    $retryAfterSeconds = max(
                        $retryAfterSeconds ?? 0,
                        $bucketDecision->retryAfterSeconds ?? 0
                    );
                }
            }

            return new RateLimitDecision(
                $blockedDimensions === [],
                $remainingAttempts === PHP_INT_MAX ? 0 : $remainingAttempts,
                $retryAfterSeconds,
                array_values(array_unique($blockedDimensions))
            );
        });
    }

    private function recordBucketAttempt(
        string $bucketHash,
        string $persistencePurpose,
        string $dimension,
        object|false $record,
        RateLimitPolicy $policy,
        \DateTimeImmutable $now
    ): RateLimitDecision {
        if ($record === false) {
            $this->repository->insert($bucketHash, $persistencePurpose, $this->format($now));
            return new RateLimitDecision(true, $policy->maximumAttempts - 1, null);
        }

        $blockedUntil = $record->blocked_until !== null
            ? new \DateTimeImmutable((string) $record->blocked_until)
            : null;
        if ($blockedUntil instanceof \DateTimeImmutable && $blockedUntil > $now) {
            return new RateLimitDecision(
                false,
                0,
                max(1, $blockedUntil->getTimestamp() - $now->getTimestamp()),
                [$dimension]
            );
        }

        $windowStarted = new \DateTimeImmutable((string) $record->window_started_at);
        if ($windowStarted->getTimestamp() + $policy->windowSeconds <= $now->getTimestamp()) {
            $this->repository->update(
                $bucketHash,
                $persistencePurpose,
                1,
                $this->format($now),
                null,
                $this->format($now)
            );
            return new RateLimitDecision(true, $policy->maximumAttempts - 1, null);
        }

        if ($blockedUntil instanceof \DateTimeImmutable) {
            $attemptCount = (int) $record->attempt_count + 1;
            $this->repository->update(
                $bucketHash,
                $persistencePurpose,
                $attemptCount,
                $this->format($windowStarted),
                null,
                $this->format($now)
            );
            return new RateLimitDecision(true, 0, null);
        }

        $attemptCount = (int) $record->attempt_count;
        if ($attemptCount >= $policy->maximumAttempts) {
            ++$attemptCount;
            $blockSeconds = $this->progressiveBlockSeconds($attemptCount, $policy);
            $newBlockedUntil = $now->modify('+' . $blockSeconds . ' seconds');
            $this->repository->update(
                $bucketHash,
                $persistencePurpose,
                $attemptCount,
                $this->format($windowStarted),
                $this->format($newBlockedUntil),
                $this->format($now)
            );
            return new RateLimitDecision(false, 0, $blockSeconds, [$dimension]);
        }

        ++$attemptCount;
        $this->repository->update(
            $bucketHash,
            $persistencePurpose,
            $attemptCount,
            $this->format($windowStarted),
            null,
            $this->format($now)
        );

        return new RateLimitDecision(
            true,
            max(0, $policy->maximumAttempts - $attemptCount),
            null
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock instanceof \Closure
            ? ($this->clock)()
            : new \DateTimeImmutable();
    }

    private function format(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s');
    }

    private function progressiveBlockSeconds(int $attemptCount, RateLimitPolicy $policy): int
    {
        $excessAttempts = max(1, $attemptCount - $policy->maximumAttempts);
        $multiplier = min(self::MAX_BACKOFF_MULTIPLIER, 2 ** min(4, $excessAttempts - 1));

        return $policy->blockSeconds * $multiplier;
    }
}
