<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryFlowService
{
    public function __construct(
        private readonly AuthenticationChallengeService $challengeService,
        private readonly AuthenticatorTOTPService $authenticatorService
    ) {
    }

    public function resolveChallenge(
        int $userId,
        ?string $token,
        ?string $purpose,
        ?string $expectedPurpose,
        string $sessionBinding,
        ?string $pendingAuthenticatorSecret
    ): RecoveryFlowChallengeResult {
        if ($token === null || $purpose === null || $purpose !== $expectedPurpose) {
            return new RecoveryFlowChallengeResult(RecoveryFlowChallengeResult::UNAVAILABLE);
        }

        $challenge = $this->challengeService->getActive($token, $sessionBinding, $purpose);
        if ($challenge === null || (int) $challenge->user_id !== $userId) {
            return new RecoveryFlowChallengeResult(RecoveryFlowChallengeResult::EXPIRED);
        }
        if ($this->requiresPendingAuthenticatorSecret($challenge) === true
            && $pendingAuthenticatorSecret === null
        ) {
            return new RecoveryFlowChallengeResult(RecoveryFlowChallengeResult::PENDING_SECRET_MISSING);
        }

        return new RecoveryFlowChallengeResult(RecoveryFlowChallengeResult::ACTIVE, $challenge);
    }

    public function verifyAuthenticator(
        int $userId,
        object $challenge,
        bool $lostFlow,
        ?string $pendingSecret,
        string $code,
        AuthenticationRateLimitContext $rateLimitContext,
        string $challengeToken,
        string $sessionBinding
    ): RecoveryAuthenticatorVerificationResult {
        if ((string) $challenge->state === 'fresh_reauthentication_pending') {
            return $this->verifyFreshAuthenticator(
                $userId,
                $challenge,
                $code,
                $rateLimitContext,
                $challengeToken,
                $sessionBinding
            );
        }

        return $this->verifyPendingAuthenticator(
            $userId,
            $challenge,
            $lostFlow,
            $pendingSecret,
            $code,
            $rateLimitContext,
            $challengeToken,
            $sessionBinding
        );
    }

    private function requiresPendingAuthenticatorSecret(object $challenge): bool
    {
        $state = (string) $challenge->state;
        if (in_array($state, [
            'authenticator_configuration_presented',
            'authenticator_verified',
            'new_authenticator_configuration_presented',
            'new_authenticator_verified',
            'new_recovery_codes_presented_unacknowledged',
        ], true) === true) {
            return true;
        }

        return (string) $challenge->purpose === AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT
            && $state === 'recovery_codes_presented_unacknowledged';
    }

    private function verifyFreshAuthenticator(
        int $userId,
        object $challenge,
        string $code,
        AuthenticationRateLimitContext $rateLimitContext,
        string $challengeToken,
        string $sessionBinding
    ): RecoveryAuthenticatorVerificationResult {
        $purpose = (string) $challenge->purpose;
        if ($this->authenticatorService->verify(
            $userId,
            $this->freshReauthenticationPurpose($purpose),
            $code,
            $rateLimitContext
        ) === false) {
            return new RecoveryAuthenticatorVerificationResult(RecoveryAuthenticatorVerificationResult::INVALID);
        }

        $fresh = $this->challengeService->transition(
            $challengeToken,
            $sessionBinding,
            $purpose,
            'fresh_reauthentication_pending',
            'freshly_reauthenticated'
        );
        if ($fresh === null) {
            return new RecoveryAuthenticatorVerificationResult(RecoveryAuthenticatorVerificationResult::EXPIRED);
        }
        if ($purpose === AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES) {
            return new RecoveryAuthenticatorVerificationResult(
                RecoveryAuthenticatorVerificationResult::GENERATE_CODES,
                $fresh,
                'freshly_reauthenticated'
            );
        }

        $warning = $this->challengeService->transition(
            $challengeToken,
            $sessionBinding,
            $purpose,
            'freshly_reauthenticated',
            'replacement_warning_presented'
        );
        return new RecoveryAuthenticatorVerificationResult(
            $warning === null
                ? RecoveryAuthenticatorVerificationResult::EXPIRED
                : RecoveryAuthenticatorVerificationResult::WARNING_PRESENTED,
            $warning
        );
    }

    private function verifyPendingAuthenticator(
        int $userId,
        object $challenge,
        bool $lostFlow,
        ?string $pendingSecret,
        string $code,
        AuthenticationRateLimitContext $rateLimitContext,
        string $challengeToken,
        string $sessionBinding
    ): RecoveryAuthenticatorVerificationResult {
        $expectedState = $lostFlow === true
            ? 'new_authenticator_configuration_presented'
            : 'authenticator_configuration_presented';
        if ((string) $challenge->state !== $expectedState || $pendingSecret === null) {
            return new RecoveryAuthenticatorVerificationResult(RecoveryAuthenticatorVerificationResult::INVALID);
        }

        $authenticatorPurpose = $lostFlow === true
            ? AuthenticatorTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY
            : AuthenticatorTOTPPurpose::AUTHENTICATOR_ENROLLMENT;
        if ($this->authenticatorService->verifyPendingSecret(
            $userId,
            $authenticatorPurpose,
            $pendingSecret,
            $code,
            $rateLimitContext
        ) === false) {
            return new RecoveryAuthenticatorVerificationResult(RecoveryAuthenticatorVerificationResult::INVALID);
        }

        $verifiedState = $lostFlow === true ? 'new_authenticator_verified' : 'authenticator_verified';
        $verified = $this->challengeService->transition(
            $challengeToken,
            $sessionBinding,
            (string) $challenge->purpose,
            $expectedState,
            $verifiedState
        );
        return new RecoveryAuthenticatorVerificationResult(
            $verified === null
                ? RecoveryAuthenticatorVerificationResult::EXPIRED
                : RecoveryAuthenticatorVerificationResult::GENERATE_CODES,
            $verified,
            $verifiedState
        );
    }

    private function freshReauthenticationPurpose(string $challengePurpose): AuthenticatorTOTPPurpose
    {
        return match ($challengePurpose) {
            AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES =>
                AuthenticatorTOTPPurpose::INITIAL_RECOVERY_CODES,
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES =>
                AuthenticatorTOTPPurpose::REPLACE_RECOVERY_CODES,
            default => throw new \DomainException('The challenge does not support authenticator reauthentication.'),
        };
    }
}
