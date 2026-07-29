<?php

declare(strict_types=1);

namespace src\Business;

use src\Data\Connection;
use src\Data\Repository\RecoveryCodeRepository;

class RecoveryCodeService
{
    public const CODE_COUNT = 10;
    public const PENDING_SET_TTL_SECONDS = 900;

    public function __construct(
        private readonly Connection $connection,
        private readonly RecoveryCodeRepository $repository,
        private readonly RecoveryCodeCodec $codec,
        private readonly AuthenticationRateLimitService $rateLimitService,
        private readonly SecurityAuditService $auditService,
        private readonly ?\Closure $clock = null
    ) {
    }

    /**
     * @return array<string, RateLimitPolicy>
     */
    private function verificationPolicies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value =>
                new RateLimitPolicy(5, 900, 900),

            AuthenticationRateLimitBucketDimension::CHALLENGE->value =>
                new RateLimitPolicy(5, 900, 900),

            AuthenticationRateLimitBucketDimension::SESSION->value =>
                new RateLimitPolicy(15, 900, 900),

            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value =>
                new RateLimitPolicy(50, 900, 900),
        ];
    }

    /**
     * @return array<string, RateLimitPolicy>
     */
    private function generationPolicies(): array
    {
        return [
            AuthenticationRateLimitBucketDimension::ACCOUNT->value =>
                new RateLimitPolicy(3, 3600, 3600),

            AuthenticationRateLimitBucketDimension::CHALLENGE->value =>
                new RateLimitPolicy(3, 3600, 3600),

            AuthenticationRateLimitBucketDimension::SESSION->value =>
                new RateLimitPolicy(6, 3600, 3600),

            AuthenticationRateLimitBucketDimension::IP_ADDRESS->value =>
                new RateLimitPolicy(30, 3600, 3600),
        ];
    }

    public function generatePendingSet(
        int $userId,
        AuthenticationRateLimitPurpose $purpose,
        AuthenticationRateLimitContext $rateLimitContext,
        int $authenticatorGeneration = 1,
        ?int $challengeId = null,
        ?int $replacesSetId = null
    ): RecoveryCodeGenerationResult {
        if ($userId <= 0
            || $authenticatorGeneration <= 0
            || $rateLimitContext->matchesUserId($userId) === false
        ) {
            throw new \InvalidArgumentException(
                'The recovery-code owner must match the rate-limit account identity.'
            );
        }

        $permit = $this->rateLimitService->consumeAttempt(
            $rateLimitContext,
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

        $codes = [];
        $hashes = [];
        while (count($codes) < self::CODE_COUNT) {
            $code = $this->codec->generate();
            $normalized = $this->codec->normalize($code);
            if ($normalized === null) {
                throw new \RuntimeException('Generated recovery code did not normalize.');
            }

            $hash = $this->codec->hash($normalized);
            if (isset($hashes[$hash]) === true) {
                continue;
            }

            $codes[] = $code;
            $hashes[$hash] = $hash;
        }

        $now = $this->now();
        $expiresAt = $now->modify('+' . self::PENDING_SET_TTL_SECONDS . ' seconds');
        $setId = $this->connection->transaction(function () use (
            $userId,
            $purpose,
            $authenticatorGeneration,
            $challengeId,
            $replacesSetId,
            $hashes,
            $now,
            $expiresAt
        ): int {
            if ($this->repository->lockUser($userId) === false) {
                throw new \DomainException('Recovery-code owner does not exist.');
            }

            $formattedNow = $this->format($now);
            $setId = $this->repository->createPendingSet(
                $userId,
                $purpose->value,
                $authenticatorGeneration,
                $challengeId,
                $replacesSetId,
                $formattedNow,
                $this->format($expiresAt)
            );
            $this->repository->insertCodeHashes($setId, array_values($hashes), $formattedNow);
            $this->auditService->record('recovery_codes.generated', $userId, [
                'recovery_code_set_id' => $setId,
                'replaces_recovery_code_set_id' => $replacesSetId,
                'purpose' => $purpose->value,
                'authenticator_generation' => $authenticatorGeneration,
            ]);

            return $setId;
        });

        return new RecoveryCodeGenerationResult($setId, $codes, $expiresAt);
    }

    public function activatePendingSet(int $userId, int $setId, ?int $expectedActiveSetId = null): bool
    {
        return $this->connection->transaction(function () use ($userId, $setId, $expectedActiveSetId): bool {
            if ($this->repository->lockUser($userId) === false) {
                return false;
            }

            $pendingSet = $this->repository->findSetForUpdate($setId);
            if ($pendingSet === false
                || (int) $pendingSet->user_id !== $userId
                || $pendingSet->status !== 'pending'
                || $pendingSet->displayed_at === null
            ) {
                return false;
            }

            $replacesSetId = $pendingSet->replaces_recovery_code_set_id === null
                ? null
                : (int) $pendingSet->replaces_recovery_code_set_id;
            if ($replacesSetId !== $expectedActiveSetId) {
                return false;
            }

            $authenticatorGeneration = $this->repository->findAuthenticatorGenerationForUpdate($userId);
            if ($authenticatorGeneration === null
                || $authenticatorGeneration !== (int) $pendingSet->authenticator_generation
            ) {
                return false;
            }

            $state = $this->repository->findStateForUpdate($userId);
            $activeSetId = $state === false || $state->active_recovery_code_set_id === null
                ? null
                : (int) $state->active_recovery_code_set_id;
            if ($activeSetId !== $expectedActiveSetId) {
                return false;
            }

            $now = $this->format($this->now());
            if ($expectedActiveSetId !== null
                && $this->repository->invalidateSet($expectedActiveSetId, $now) === false
            ) {
                return false;
            }

            if ($this->repository->activateSet($setId, $now) === false) {
                throw new \RuntimeException('Unable to activate the pending recovery-code set.');
            }

            $this->repository->setActiveSet($userId, $setId, $now);
            $event = $expectedActiveSetId === null
                ? 'recovery_codes.activated'
                : 'recovery_codes.replaced';
            $this->auditService->record($event, $userId, [
                'recovery_code_set_id' => $setId,
                'replaces_recovery_code_set_id' => $expectedActiveSetId,
                'remaining_count' => self::CODE_COUNT,
                'purpose' => (string) $pendingSet->purpose,
                'authenticator_generation' => (int) $pendingSet->authenticator_generation,
            ]);

            return true;
        });
    }

    public function invalidatePendingSet(int $userId, int $setId, string $reason): bool
    {
        return $this->connection->transaction(function () use ($userId, $setId, $reason): bool {
            if ($this->repository->lockUser($userId) === false) {
                return false;
            }

            $set = $this->repository->findSetForUpdate($setId);
            if ($set === false || (int) $set->user_id !== $userId || $set->status !== 'pending') {
                return false;
            }

            $invalidated = $this->repository->invalidateSet($setId, $this->format($this->now()));
            if ($invalidated === true) {
                $this->auditService->record('recovery_codes.pending_invalidated', $userId, [
                    'recovery_code_set_id' => $setId,
                    'reason' => $reason,
                    'purpose' => (string) $set->purpose,
                    'authenticator_generation' => (int) $set->authenticator_generation,
                ]);
            }

            return $invalidated;
        });
    }

    public function consumeActiveCode(
        int $userId,
        string $submittedCode,
        AuthenticationRateLimitContext $rateLimitContext,
        AuthenticationRateLimitPurpose $purpose
    ): RecoveryCodeConsumptionResult {
        if ($userId <= 0 || $rateLimitContext->matchesUserId($userId) === false) {
            throw new \InvalidArgumentException(
                'The recovery-code owner must match the rate-limit account identity.'
            );
        }

        $permit = $this->rateLimitService->consumeAttempt(
            $rateLimitContext,
            AuthenticationRateLimitAction::RECOVERY_CODE_VERIFY,
            $purpose,
            $this->verificationPolicies()
        );
        if ($permit->allowed === false) {
            return new RecoveryCodeConsumptionResult(
                RecoveryCodeConsumptionResult::STATUS_RATE_LIMITED,
                null,
                $permit->retryAfterSeconds
            );
        }

        $normalized = $this->codec->normalize($submittedCode);
        if ($normalized === null) {
            return new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
        }

        $hash = $this->codec->hash($normalized);
        $result = $this->connection->transaction(function () use ($userId, $hash, $purpose): RecoveryCodeConsumptionResult {
            if ($this->repository->lockUser($userId) === false) {
                return new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
            }

            $activeSet = $this->repository->findActiveSetForUpdate($userId);
            if ($activeSet === false) {
                return new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
            }

            $now = $this->format($this->now());
            if ($this->repository->consumeUnusedHash((int) $activeSet->id, $hash, $now) === false) {
                return new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
            }

            $remainingCount = $this->repository->countUnused((int) $activeSet->id);
            $this->auditService->record('recovery_code.used', $userId, [
                'recovery_code_set_id' => (int) $activeSet->id,
                'remaining_count' => $remainingCount,
                'purpose' => $purpose->value,
                'authenticator_generation' => (int) $activeSet->authenticator_generation,
            ]);

            return new RecoveryCodeConsumptionResult(
                RecoveryCodeConsumptionResult::STATUS_CONSUMED,
                $remainingCount
            );
        });

        if ($result->isConsumed()) {
            $this->rateLimitService->resetAfterSuccessfulCredentialVerification(
                $rateLimitContext,
                $purpose
            );
        }

        return $result;
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
}
