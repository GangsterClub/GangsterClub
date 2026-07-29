<?php

declare(strict_types=1);

namespace src\Business;

use src\Data\Connection;
use src\Data\Repository\AuthenticationChallengeRepository;

class AuthenticationChallengeService
{
    public const PURPOSE_AUTHENTICATOR_ENROLLMENT = 'authenticator_enrollment';
    public const PURPOSE_LOST_AUTHENTICATOR_RECOVERY = 'lost_authenticator_recovery';
    public const PURPOSE_INITIAL_RECOVERY_CODES = 'initial_recovery_codes';
    public const PURPOSE_REPLACE_RECOVERY_CODES = 'replace_recovery_codes';

    public const ENROLLMENT_TTL_SECONDS = 900;
    public const LOST_AUTHENTICATOR_TTL_SECONDS = 900;
    public const RECOVERY_CODE_CEREMONY_TTL_SECONDS = 900;
    public const FRESH_REAUTHENTICATION_TTL_SECONDS = 300;

    private const INITIAL_STATES = [
        self::PURPOSE_AUTHENTICATOR_ENROLLMENT => 'email_verification_pending',
        self::PURPOSE_LOST_AUTHENTICATOR_RECOVERY => 'email_verification_pending',
        self::PURPOSE_INITIAL_RECOVERY_CODES => 'fresh_reauthentication_pending',
        self::PURPOSE_REPLACE_RECOVERY_CODES => 'fresh_reauthentication_pending',
    ];

    private const TRANSITIONS = [
        self::PURPOSE_AUTHENTICATOR_ENROLLMENT => [
            'email_verification_pending' => 'email_verified',
            'email_verified' => 'authenticator_configuration_presented',
            'authenticator_configuration_presented' => 'authenticator_verified',
            'authenticator_verified' => 'recovery_codes_presented_unacknowledged',
            'recovery_codes_presented_unacknowledged' => 'recovery_codes_acknowledged',
            'recovery_codes_acknowledged' => 'completed',
        ],
        self::PURPOSE_LOST_AUTHENTICATOR_RECOVERY => [
            'email_verification_pending' => 'email_verified',
            'email_verified' => 'recovery_code_pending',
            'recovery_code_pending' => 'recovery_code_consumed',
            'recovery_code_consumed' => 'replacement_warning_presented',
            'replacement_warning_presented' => 'new_authenticator_configuration_presented',
            'new_authenticator_configuration_presented' => 'new_authenticator_verified',
            'new_authenticator_verified' => 'new_recovery_codes_presented_unacknowledged',
            'new_recovery_codes_presented_unacknowledged' => 'new_recovery_codes_acknowledged',
            'new_recovery_codes_acknowledged' => 'replacement_completed',
        ],
        self::PURPOSE_INITIAL_RECOVERY_CODES => [
            'fresh_reauthentication_pending' => 'freshly_reauthenticated',
            'freshly_reauthenticated' => 'recovery_codes_presented_unacknowledged',
            'recovery_codes_presented_unacknowledged' => 'recovery_codes_acknowledged',
            'recovery_codes_acknowledged' => 'completed',
        ],
        self::PURPOSE_REPLACE_RECOVERY_CODES => [
            'fresh_reauthentication_pending' => 'freshly_reauthenticated',
            'freshly_reauthenticated' => 'replacement_warning_presented',
            'replacement_warning_presented' => 'replacement_confirmed',
            'replacement_confirmed' => 'replacement_codes_presented_unacknowledged',
            'replacement_codes_presented_unacknowledged' => 'replacement_acknowledged',
            'replacement_acknowledged' => 'completed',
        ],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly AuthenticationChallengeRepository $repository,
        private readonly string $pepper,
        private readonly ?\Closure $clock = null
    ) {
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('The authentication challenge pepper must contain at least 32 bytes.');
        }
    }

    public function start(
        int $userId,
        string $purpose,
        string $sessionId,
        ?int $baselineAuthenticatorGeneration = null,
        ?int $baselineRecoveryCodeSetId = null
    ): array {
        if ($userId <= 0 || $sessionId === '' || isset(self::INITIAL_STATES[$purpose]) === false) {
            throw new \InvalidArgumentException('A valid user, purpose, and session are required.');
        }

        $now = $this->now();
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = $now->modify('+' . $this->ttlForPurpose($purpose) . ' seconds');

        $id = $this->connection->transaction(function () use (
            $userId,
            $purpose,
            $sessionId,
            $baselineAuthenticatorGeneration,
            $baselineRecoveryCodeSetId,
            $now,
            $rawToken,
            $expiresAt
        ): int {
            $formattedNow = $this->format($now);
            if ($this->repository->lockUser($userId) === false) {
                throw new \DomainException('Authentication challenge owner does not exist.');
            }
            $this->repository->cancelActiveForUserAndPurpose($userId, $purpose, $formattedNow);

            return $this->repository->create([
                'token_hash' => $this->hashToken($rawToken),
                'user_id' => $userId,
                'purpose' => $purpose,
                'state' => self::INITIAL_STATES[$purpose],
                'session_binding_hash' => $this->hashSession($sessionId),
                'baseline_authenticator_generation' => $baselineAuthenticatorGeneration,
                'baseline_recovery_code_set_id' => $baselineRecoveryCodeSetId,
                'expires_at' => $this->format($expiresAt),
            ]);
        });

        return [
            'id' => $id,
            'token' => $rawToken,
            'purpose' => $purpose,
            'state' => self::INITIAL_STATES[$purpose],
            'expires_at' => $expiresAt,
        ];
    }

    public function getActive(string $rawToken, string $sessionId, string $purpose): ?object
    {
        if ($rawToken === '' || $sessionId === '') {
            return null;
        }

        $record = $this->repository->findBound($this->hashToken($rawToken), $this->hashSession($sessionId));
        if ($record === false || $record->purpose !== $purpose || $this->isActive($record) === false) {
            return null;
        }

        return $record;
    }

    public function transition(
        string $rawToken,
        string $sessionId,
        string $purpose,
        string $expectedState,
        string $nextState
    ): ?object {
        if ((self::TRANSITIONS[$purpose][$expectedState] ?? null) !== $nextState) {
            throw new \DomainException('The requested authentication challenge transition is not allowed.');
        }

        return $this->connection->transaction(function () use (
            $rawToken,
            $sessionId,
            $purpose,
            $expectedState,
            $nextState
        ): ?object {
            $record = $this->repository->findBoundForUpdate(
                $this->hashToken($rawToken),
                $this->hashSession($sessionId)
            );
            if ($record === false
                || $record->purpose !== $purpose
                || $record->state !== $expectedState
                || $this->isActive($record) === false
            ) {
                return null;
            }

            $now = $this->now();
            $reauthenticatedAt = $nextState === 'freshly_reauthenticated'
                ? $this->format($now)
                : null;
            $completedAt = in_array($nextState, ['completed', 'replacement_completed'], true)
                ? $this->format($now)
                : null;
            if ($this->repository->transition(
                (int) $record->id,
                $expectedState,
                $nextState,
                $this->format($now),
                $reauthenticatedAt,
                $completedAt
            ) === false) {
                return null;
            }

            return $this->repository->findBound(
                $this->hashToken($rawToken),
                $this->hashSession($sessionId)
            ) ?: null;
        });
    }

    public function cancel(string $rawToken, string $sessionId, string $purpose): bool
    {
        return $this->connection->transaction(function () use ($rawToken, $sessionId, $purpose): bool {
            $record = $this->repository->findBoundForUpdate(
                $this->hashToken($rawToken),
                $this->hashSession($sessionId)
            );
            if ($record === false || $record->purpose !== $purpose || $this->isActive($record) === false) {
                return false;
            }

            return $this->repository->cancel((int) $record->id, $this->format($this->now()));
        });
    }

    public function isFreshReauthentication(object $record, string $purpose): bool
    {
        if ($record->purpose !== $purpose
            || $record->reauthenticated_at === null
            || $record->completed_at !== null
            || $record->cancelled_at !== null
            || strtotime((string) $record->expires_at) < $this->now()->getTimestamp()
        ) {
            return false;
        }

        return strtotime((string) $record->reauthenticated_at) >= $this->now()->getTimestamp() - self::FRESH_REAUTHENTICATION_TTL_SECONDS;
    }

    private function ttlForPurpose(string $purpose): int
    {
        return match ($purpose) {
            self::PURPOSE_LOST_AUTHENTICATOR_RECOVERY => self::LOST_AUTHENTICATOR_TTL_SECONDS,
            self::PURPOSE_AUTHENTICATOR_ENROLLMENT => self::ENROLLMENT_TTL_SECONDS,
            default => self::RECOVERY_CODE_CEREMONY_TTL_SECONDS,
        };
    }

    private function isActive(object $record): bool
    {
        return $record->completed_at === null
            && $record->cancelled_at === null
            && strtotime((string) $record->expires_at) >= $this->now()->getTimestamp();
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', 'token:' . $token, $this->pepper);
    }

    private function hashSession(string $sessionId): string
    {
        return hash_hmac('sha256', 'session:' . $sessionId, $this->pepper);
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
