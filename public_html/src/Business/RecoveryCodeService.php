<?php

declare(strict_types=1);

namespace src\Business;

use src\Data\Connection;
use src\Data\RecoveryAccountPersistence;
use src\Data\RecoveryCodePersistence;

class RecoveryCodeService
{
    public const CODE_COUNT = 10;
    public const PENDING_SET_TTL_SECONDS = 900;

    public function __construct(
        private readonly Connection $connection,
        private readonly RecoveryCodePersistence $recoveryData,
        private readonly RecoveryAccountPersistence $accountData,
        private readonly RecoveryCodeCodec $codec,
        private readonly RecoveryCodeGenerator $generator,
        private readonly RecoveryCodeRateLimiter $limiter,
        private readonly SecurityAuditService $auditService,
        private readonly ?\Closure $clock = null
    ) {
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

        $this->limiter->requireGenerationPermit($rateLimitContext, $purpose);
        $generated = $this->generator->generate(self::CODE_COUNT);
        $codes = $generated['codes'];
        $hashes = $generated['hashes'];

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
            if ($this->accountData->userLocks()->lock($userId) === false) {
                throw new \DomainException('Recovery-code owner does not exist.');
            }

            $formattedNow = $this->format($now);
            $setId = $this->recoveryData->sets()->createPending(
                $userId,
                $purpose->value,
                $authenticatorGeneration,
                $challengeId,
                $replacesSetId,
                $formattedNow,
                $this->format($expiresAt)
            );
            $this->recoveryData->codes()->insertAll($setId, $hashes, $formattedNow);
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
            if ($this->accountData->userLocks()->lock($userId) === false) {
                return false;
            }

            $pendingSet = $this->recoveryData->sets()->findForUpdate($setId);
            if ($this->isActivatablePendingSet($pendingSet, $userId, $expectedActiveSetId) === false) {
                return false;
            }

            if ($this->matchesAuthenticatorGeneration($pendingSet, $userId) === false) {
                return false;
            }

            if ($this->getLockedActiveSetId($userId) !== $expectedActiveSetId) {
                return false;
            }

            $now = $this->format($this->now());
            if ($this->invalidateReplacedSet($expectedActiveSetId, $now) === false) {
                return false;
            }

            if ($this->recoveryData->sets()->activate($setId, $now) === false) {
                throw new \RuntimeException('Unable to activate the pending recovery-code set.');
            }

            $this->recoveryData->states()->setActiveSet($userId, $setId, $now);
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

    private function isActivatablePendingSet(object|false $pendingSet, int $userId, ?int $expectedSetId): bool
    {
        if ($pendingSet === false) {
            return false;
        }

        $replacesSetId = $pendingSet->replaces_recovery_code_set_id === null
            ? null
            : (int) $pendingSet->replaces_recovery_code_set_id;
        return (int) $pendingSet->user_id === $userId
            && $pendingSet->status === 'pending'
            && $pendingSet->displayed_at !== null
            && $replacesSetId === $expectedSetId;
    }

    private function matchesAuthenticatorGeneration(object $pendingSet, int $userId): bool
    {
        $authenticator = $this->accountData->authenticators()->findByUserIdForUpdate($userId);
        return $authenticator !== false
            && (int) $authenticator->generation === (int) $pendingSet->authenticator_generation;
    }

    private function getLockedActiveSetId(int $userId): ?int
    {
        return $this->recoveryData->states()->getActiveSetId($userId, true);
    }

    private function invalidateReplacedSet(?int $setId, string $now): bool
    {
        return $setId === null || $this->recoveryData->sets()->invalidate($setId, $now) === true;
    }

    public function invalidatePendingSet(int $userId, int $setId, string $reason): bool
    {
        return $this->connection->transaction(function () use ($userId, $setId, $reason): bool {
            if ($this->accountData->userLocks()->lock($userId) === false) {
                return false;
            }

            $set = $this->recoveryData->sets()->findForUpdate($setId);
            if ($set === false || (int) $set->user_id !== $userId || $set->status !== 'pending') {
                return false;
            }

            $invalidated = $this->recoveryData->sets()->invalidate($setId, $this->format($this->now()));
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

        $permit = $this->limiter->verificationPermit($rateLimitContext, $purpose);
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
            if ($this->accountData->userLocks()->lock($userId) === false) {
                return new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
            }

            $activeSet = $this->findActiveSetForUpdate($userId);
            if ($activeSet === false) {
                return new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
            }

            $now = $this->format($this->now());
            if ($this->recoveryData->codes()->consume((int) $activeSet->id, $hash, $now) === false) {
                return new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
            }

            $remainingCount = $this->recoveryData->codes()->countUnused((int) $activeSet->id);
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

        if ($result->isConsumed() === true) {
            $this->limiter->resetVerification($rateLimitContext, $purpose);
        }

        return $result;
    }

    private function findActiveSetForUpdate(int $userId): object|false
    {
        $setId = $this->recoveryData->states()->getActiveSetId($userId, true);
        if ($setId === null) {
            return false;
        }
        $set = $this->recoveryData->sets()->findActive($setId, $userId, true);
        $authenticator = $this->accountData->authenticators()->findByUserIdForUpdate($userId);
        if ($set === false || $authenticator === false) {
            return false;
        }
        return (int) $authenticator->generation === (int) $set->authenticator_generation
            ? $set
            : false;
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
