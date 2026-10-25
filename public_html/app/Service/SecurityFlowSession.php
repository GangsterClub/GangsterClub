<?php

declare(strict_types=1);

namespace app\Service;

final class SecurityFlowSession
{
    public function __construct(private readonly SessionService $session)
    {
    }

    public function setPendingAuthenticatorSecret(?string $secret): void
    {
        $this->setString(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET, $secret);
    }

    public function getPendingAuthenticatorSecret(): ?string
    {
        return $this->getString(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET);
    }

    public function clearPendingAuthenticatorSetup(): void
    {
        $this->session->remove(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET);
        $this->session->remove(AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET);
    }

    public function getAuthenticatorSetupEmailSessionKey(): string { return AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET; }
    public function getSecurityEmailSessionKey(): string { return AuthSessionKeys::SECURITY_EMAIL_SECRET; }

    public function getChallengeBinding(): string
    {
        $binding = $this->getString(AuthSessionKeys::SECURITY_CHALLENGE_BINDING);
        if ($binding === null) {
            $binding = bin2hex(random_bytes(32));
            $this->session->set(AuthSessionKeys::SECURITY_CHALLENGE_BINDING, $binding);
        }
        return $binding;
    }

    public function setChallenge(string $token, string $purpose): void
    {
        $this->session->set(AuthSessionKeys::SECURITY_CHALLENGE_TOKEN, $token);
        $this->session->set(AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE, $purpose);
    }

    public function getChallengeToken(): ?string { return $this->getString(AuthSessionKeys::SECURITY_CHALLENGE_TOKEN); }
    public function getChallengePurpose(): ?string { return $this->getString(AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE); }

    public function setPendingRecoverySetId(?int $setId): void
    {
        if ($setId === null || $setId <= 0) {
            $this->session->remove(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID);
            return;
        }
        $this->session->set(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID, $setId);
    }

    public function getPendingRecoverySetId(): ?int
    {
        $setId = (int) $this->session->get(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID, 0);
        return $setId > 0 ? $setId : null;
    }

    public function markRecoveryCodesDelivered(int $setId): void
    {
        if ($setId <= 0) {
            throw new \InvalidArgumentException('A valid delivered recovery-code set is required.');
        }
        $this->session->set(AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED, $setId);
    }

    public function getDeliveredRecoverySetId(): ?int
    {
        $setId = (int) $this->session->get(AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED, 0);
        return $setId > 0 ? $setId : null;
    }

    public function clear(): void
    {
        foreach ([
            AuthSessionKeys::SECURITY_CHALLENGE_TOKEN,
            AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE,
            AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID,
            AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED,
            AuthSessionKeys::SECURITY_EMAIL_SECRET,
            AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET,
        ] as $key) {
            $this->session->remove($key);
        }
    }

    private function setString(string $key, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $this->session->remove($key);
            return;
        }
        $this->session->set($key, $value);
    }

    private function getString(string $key): ?string
    {
        $value = $this->session->get($key);
        return is_string($value) === true && $value !== '' ? $value : null;
    }
}
