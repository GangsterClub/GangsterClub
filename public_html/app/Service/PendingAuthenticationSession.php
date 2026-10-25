<?php

declare(strict_types=1);

namespace app\Service;

final class PendingAuthenticationSession
{
    public function __construct(private readonly SessionService $session)
    {
    }

    public function setEmail(?string $email): void { $this->setString(AuthSessionKeys::PENDING_LOGIN_EMAIL, $email); }
    public function getEmail(): ?string { return $this->getString(AuthSessionKeys::PENDING_LOGIN_EMAIL); }
    public function setTotp(?string $otp): void { $this->setString(AuthSessionKeys::PENDING_LOGIN_TOTP, $otp); }
    public function getTotp(): ?string { return $this->getString(AuthSessionKeys::PENDING_LOGIN_TOTP); }

    public function setUserId(?int $userId): void
    {
        if ($userId === null || $userId <= 0) {
            $this->session->remove(AuthSessionKeys::PENDING_USER_ID);
            return;
        }
        $this->session->set(AuthSessionKeys::PENDING_USER_ID, $userId);
    }

    public function getUserId(): ?int
    {
        $userId = (int) $this->session->get(AuthSessionKeys::PENDING_USER_ID, 0);
        return $userId > 0 ? $userId : null;
    }

    public function setAuthenticatorRequired(bool $required): void
    {
        $this->session->set(AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED, $required);
    }

    public function isAuthenticatorRequired(): bool
    {
        return (bool) $this->session->get(AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED, false);
    }

    public function setTwoStepRequired(bool $required): void
    {
        $this->session->set(AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED, $required);
    }

    public function isTwoStepRequired(): bool
    {
        return $this->session->get(AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED, false) === true;
    }

    public function clear(): void
    {
        foreach ([
            AuthSessionKeys::PENDING_USER_ID,
            AuthSessionKeys::PENDING_LOGIN_EMAIL,
            AuthSessionKeys::PENDING_LOGIN_TOTP,
            AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED,
            AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED,
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
        return is_string($value) && $value !== '' ? $value : null;
    }
}
