<?php

declare(strict_types=1);

namespace app\Service;

class AuthService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly CsrfService $csrfService
    ) {
    }

    public function loginUser(int $userId): void
    {
        $this->sessionService->regenerate();
        $this->setAuthenticatedUserId($userId);
        $this->clearPendingAuthentication();
        $this->rotateCsrfToken();
    }

    public function logoutUser(bool $regenerateSession = true): void
    {
        foreach ([
            AuthSessionKeys::AUTHENTICATED_USER_ID,
            AuthSessionKeys::PENDING_USER_ID,
            AuthSessionKeys::PENDING_LOGIN_EMAIL,
            AuthSessionKeys::PENDING_LOGIN_TOTP,
            AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED,
            AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET,
            AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET,
            AuthSessionKeys::JWT_TOKEN,
        ] as $key) {
            $this->sessionService->remove($key);
        }

        if ($regenerateSession === true) {
            $this->sessionService->regenerate();
        }

        $this->rotateCsrfToken();
    }

    public function getAuthenticatedUserId(): ?int
    {
        $userId = $this->sessionService->get(AuthSessionKeys::AUTHENTICATED_USER_ID);
        if ($userId === null || $userId === '') {
            return null;
        }

        $userId = (int) $userId;
        return $userId > 0 ? $userId : null;
    }

    public function setPendingLoginEmail(?string $email): void
    {
        $this->setStringValue(AuthSessionKeys::PENDING_LOGIN_EMAIL, $email);
    }

    public function getPendingLoginEmail(): ?string
    {
        return $this->getStringValue(AuthSessionKeys::PENDING_LOGIN_EMAIL);
    }

    public function setPendingLoginTotp(?string $otp): void
    {
        $this->setStringValue(AuthSessionKeys::PENDING_LOGIN_TOTP, $otp);
    }

    public function getPendingLoginTotp(): ?string
    {
        return $this->getStringValue(AuthSessionKeys::PENDING_LOGIN_TOTP);
    }

    public function setPendingUserId(?int $userId): void
    {
        if ($userId === null || $userId <= 0) {
            $this->sessionService->remove(AuthSessionKeys::PENDING_USER_ID);
            return;
        }

        $this->sessionService->set(AuthSessionKeys::PENDING_USER_ID, $userId);
    }

    public function getPendingUserId(): ?int
    {
        $userId = $this->sessionService->get(AuthSessionKeys::PENDING_USER_ID);
        if ($userId === null || $userId === '') {
            return null;
        }

        $userId = (int) $userId;
        return $userId > 0 ? $userId : null;
    }

    public function setLoginAuthenticatorRequired(bool $required): void
    {
        $this->sessionService->set(AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED, $required);
    }

    public function isLoginAuthenticatorRequired(): bool
    {
        return (bool) $this->sessionService->get(AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED, false);
    }

    public function setPendingAuthenticatorSecret(?string $secret): void
    {
        $this->setStringValue(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET, $secret);
    }

    public function getPendingAuthenticatorSecret(): ?string
    {
        return $this->getStringValue(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET);
    }

    public function clearPendingAuthenticatorSetup(): void
    {
        $this->sessionService->remove(AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET);
        $this->sessionService->remove(AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET);
    }

    public function getAuthenticatorSetupEmailSessionKey(): string
    {
        return AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET;
    }

    private function setAuthenticatedUserId(int $userId): void
    {
        $this->sessionService->set(AuthSessionKeys::AUTHENTICATED_USER_ID, $userId);
    }

    private function clearPendingAuthentication(): void
    {
        $this->sessionService->remove(AuthSessionKeys::PENDING_USER_ID);
        $this->sessionService->remove(AuthSessionKeys::PENDING_LOGIN_EMAIL);
        $this->sessionService->remove(AuthSessionKeys::PENDING_LOGIN_TOTP);
        $this->sessionService->remove(AuthSessionKeys::LOGIN_AUTHENTICATOR_REQUIRED);
    }

    private function setStringValue(string $key, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $this->sessionService->remove($key);
            return;
        }

        $this->sessionService->set($key, $value);
    }

    private function getStringValue(string $key): ?string
    {
        $value = $this->sessionService->get($key);
        if (is_string($value) === false || $value === '') {
            return null;
        }

        return $value;
    }

    public function rotateCsrfToken(): void
    {
        $this->csrfService->rotateToken();
    }
}
