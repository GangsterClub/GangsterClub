<?php

declare(strict_types=1);

namespace src\Business;

use src\Data\Connection;
use src\Data\Repository\RecoveryCodeRepository;
use src\Data\Repository\UserAuthenticatorTOTPRepository;
use src\Data\Repository\UserRepository;

class RecoveryFeatureService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AuthenticationChallengeService $challengeService,
        private readonly RecoveryCodeService $recoveryCodeService,
        private readonly RecoveryCodeRepository $recoveryCodeRepository,
        private readonly UserAuthenticatorTOTPRepository $authenticatorRepository,
        private readonly UserRepository $userRepository,
        private readonly SecurityAuditService $auditService
    ) {
    }

    public function getAuthenticatorGeneration(int $userId): ?int
    {
        $authenticator = $this->authenticatorRepository->findByUserId($userId);
        return $authenticator === false ? null : (int) ($authenticator->generation ?? 1);
    }

    public function getActiveRecoverySetId(int $userId): ?int
    {
        return $this->recoveryCodeRepository->getActiveSetId($userId);
    }

    public function getUnusedRecoveryCodeCount(int $userId): int
    {
        return $this->recoveryCodeRepository->countActiveUnused($userId);
    }

    public function completeEnrollment(
        int $userId,
        int $pendingSetId,
        string $pendingSecret,
        string $challengeToken,
        string $sessionBinding
    ): bool {
        return $this->connection->transaction(function () use (
            $userId,
            $pendingSetId,
            $pendingSecret,
            $challengeToken,
            $sessionBinding
        ): bool {
            if ($this->recoveryCodeRepository->lockUser($userId) === false
                || $this->authenticatorRepository->findByUserId($userId) !== false
            ) {
                return false;
            }

            if ($this->challengeService->transition(
                $challengeToken,
                $sessionBinding,
                AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT,
                'recovery_codes_presented_unacknowledged',
                'recovery_codes_acknowledged'
            ) === null) {
                return false;
            }

            if ($this->authenticatorRepository->upsertSecret(
                $userId,
                $pendingSecret,
                (int) AUTHENTICATOR_TOTP_DIGITS,
                (int) AUTHENTICATOR_TOTP_PERIOD
            ) === false) {
                throw new \RuntimeException('Unable to activate the pending authenticator.');
            }

            if ($this->recoveryCodeService->activatePendingSet($userId, $pendingSetId) === false) {
                throw new \RuntimeException('Unable to activate the enrollment recovery-code set.');
            }

            if ($this->challengeService->transition(
                $challengeToken,
                $sessionBinding,
                AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT,
                'recovery_codes_acknowledged',
                'completed'
            ) === null) {
                throw new \RuntimeException('Unable to complete the authenticator enrollment challenge.');
            }

            $this->auditService->record('authenticator.enrolled', $userId, [
                'recovery_code_set_id' => $pendingSetId,
                'purpose' => AuthenticationRateLimitPurpose::AUTHENTICATOR_ENROLLMENT->value,
                'authenticator_generation' => 1,
            ]);

            return true;
        });
    }

    public function completeRecoverySetActivation(
        int $userId,
        int $pendingSetId,
        ?int $expectedActiveSetId,
        string $challengeToken,
        string $sessionBinding,
        string $purpose
    ): bool {
        $states = match ($purpose) {
            AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES => [
                'presented' => 'recovery_codes_presented_unacknowledged',
                'acknowledged' => 'recovery_codes_acknowledged',
            ],
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES => [
                'presented' => 'replacement_codes_presented_unacknowledged',
                'acknowledged' => 'replacement_acknowledged',
            ],
            default => throw new \InvalidArgumentException('Unsupported recovery-set activation purpose.'),
        };

        return $this->connection->transaction(function () use (
            $userId,
            $pendingSetId,
            $expectedActiveSetId,
            $challengeToken,
            $sessionBinding,
            $purpose,
            $states
        ): bool {
            if ($this->challengeService->transition(
                $challengeToken,
                $sessionBinding,
                $purpose,
                $states['presented'],
                $states['acknowledged']
            ) === null) {
                return false;
            }

            if ($this->recoveryCodeService->activatePendingSet(
                $userId,
                $pendingSetId,
                $expectedActiveSetId
            ) === false) {
                throw new \RuntimeException('Unable to activate the pending recovery-code set.');
            }

            if ($this->challengeService->transition(
                $challengeToken,
                $sessionBinding,
                $purpose,
                $states['acknowledged'],
                'completed'
            ) === null) {
                throw new \RuntimeException('Unable to complete the recovery-code challenge.');
            }

            return true;
        });
    }

    public function completeLostAuthenticatorReplacement(
        int $userId,
        int $pendingSetId,
        int $expectedActiveSetId,
        int $expectedGeneration,
        string $pendingSecret,
        string $challengeToken,
        string $sessionBinding
    ): ?int {
        return $this->connection->transaction(function () use (
            $userId,
            $pendingSetId,
            $expectedActiveSetId,
            $expectedGeneration,
            $pendingSecret,
            $challengeToken,
            $sessionBinding
        ): ?int {
            if ($this->recoveryCodeRepository->lockUser($userId) === false) {
                return null;
            }

            if ($this->challengeService->transition(
                $challengeToken,
                $sessionBinding,
                AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY,
                'new_recovery_codes_presented_unacknowledged',
                'new_recovery_codes_acknowledged'
            ) === null) {
                return null;
            }

            $newGeneration = $expectedGeneration + 1;
            if ($this->authenticatorRepository->replaceSecretForGeneration(
                $userId,
                $expectedGeneration,
                $newGeneration,
                $pendingSecret,
                (int) AUTHENTICATOR_TOTP_DIGITS,
                (int) AUTHENTICATOR_TOTP_PERIOD
            ) === false) {
                throw new \RuntimeException('The active authenticator changed during replacement.');
            }

            if ($this->recoveryCodeService->activatePendingSet(
                $userId,
                $pendingSetId,
                $expectedActiveSetId
            ) === false) {
                throw new \RuntimeException('Unable to activate replacement recovery codes.');
            }

            if ($this->challengeService->transition(
                $challengeToken,
                $sessionBinding,
                AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY,
                'new_recovery_codes_acknowledged',
                'replacement_completed'
            ) === null) {
                throw new \RuntimeException('Unable to complete lost-authenticator replacement.');
            }

            $browserSessionVersion = $this->userRepository->incrementBrowserSessionVersion($userId);
            if ($browserSessionVersion === null) {
                throw new \RuntimeException('Unable to revoke other browser sessions.');
            }

            $this->auditService->record('authenticator.replaced', $userId, [
                'recovery_code_set_id' => $pendingSetId,
                'replaces_recovery_code_set_id' => $expectedActiveSetId,
                'purpose' => AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY->value,
                'authenticator_generation' => $newGeneration,
            ]);

            return $browserSessionVersion;
        });
    }

    public function disableAuthenticatorAndRecoveryCodes(int $userId): bool
    {
        return $this->connection->transaction(function () use ($userId): bool {
            if ($this->recoveryCodeRepository->lockUser($userId) === false) {
                return false;
            }

            $now = date('Y-m-d H:i:s');
            $activeSet = $this->recoveryCodeRepository->findActiveSetForUpdate($userId);

            if (
                $activeSet !== false
                && $this->recoveryCodeRepository->invalidateSet(
                    (int) $activeSet->id,
                    $now
                ) === false
            ) {
                throw new \RuntimeException(
                    'Unable to invalidate recovery codes during authenticator removal.'
                );
            }

            if ($this->recoveryCodeRepository->clearActiveSet($userId, $now) === false) {
                throw new \RuntimeException(
                    'Unable to clear recovery-code state during authenticator removal.'
                );
            }

            if ($this->authenticatorRepository->deleteByUserId($userId) === false) {
                throw new \RuntimeException(
                    'Unable to remove the authenticator.'
                );
            }

            $this->auditService->record('authenticator.removed', $userId, [
                'recovery_code_set_id' => $activeSet === false
                    ? null
                    : (int) $activeSet->id,
                'reason' => 'verified_authenticator_removal',
            ]);

            return true;
        });
    }
}
