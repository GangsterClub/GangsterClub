<?php

declare(strict_types=1);

namespace app\Service;

use app\Container\Application;
use app\Service\CsrfService;
use src\Business\UserService;
use src\Entity\User;

class AuthService
{
    public function __construct(private readonly Application $application)
    {
    }

    public function loginUser(int $userId): void
    {
        $this->session()->regenerate();
        $this->setAuthenticatedUserId($userId);
        $this->clearPendingAuthentication();
        $this->rotateCsrfToken();
    }

    public function loginUserWithToken(int $userId, string $jwtToken): void
    {
        $this->loginUser($userId);
        $this->storeJwtToken($jwtToken);
    }

    public function logoutUser(bool $regenerateSession = true): void
    {
        foreach ([
            AuthSessionKeys::AUTHENTICATED_USER_ID,
            AuthSessionKeys::PENDING_USER_ID,
            AuthSessionKeys::PENDING_LOGIN_EMAIL,
            AuthSessionKeys::PENDING_LOGIN_TOTP,
            AuthSessionKeys::LOGIN_MFA_REQUIRED,
            AuthSessionKeys::PENDING_MFA_SECRET,
            AuthSessionKeys::MFA_SETUP_EMAIL_SECRET,
            AuthSessionKeys::JWT_TOKEN,
        ] as $key) {
            $this->session()->remove($key);
        }

        if ($regenerateSession === true) {
            $this->session()->regenerate();
        }

        $this->rotateCsrfToken();
    }

    public function getAuthenticatedUserId(): ?int
    {
        $userId = $this->session()->get(AuthSessionKeys::AUTHENTICATED_USER_ID);
        if ($userId === null || $userId === '') {
            return null;
        }

        $userId = (int) $userId;
        return $userId > 0 ? $userId : null;
    }

    public function getAuthenticatedUser(): ?User
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId === null) {
            return null;
        }

        $userService = new UserService($this->application);
        return $userService->getUserById($userId);
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
            $this->session()->remove(AuthSessionKeys::PENDING_USER_ID);
            return;
        }

        $this->session()->set(AuthSessionKeys::PENDING_USER_ID, $userId);
    }

    public function getPendingUserId(): ?int
    {
        $userId = $this->session()->get(AuthSessionKeys::PENDING_USER_ID);
        if ($userId === null || $userId === '') {
            return null;
        }

        $userId = (int) $userId;
        return $userId > 0 ? $userId : null;
    }

    public function setLoginMfaRequired(bool $required): void
    {
        $this->session()->set(AuthSessionKeys::LOGIN_MFA_REQUIRED, $required);
    }

    public function isLoginMfaRequired(): bool
    {
        return (bool) $this->session()->get(AuthSessionKeys::LOGIN_MFA_REQUIRED, false);
    }

    public function setPendingMfaSecret(?string $secret): void
    {
        $this->setStringValue(AuthSessionKeys::PENDING_MFA_SECRET, $secret);
    }

    public function getPendingMfaSecret(): ?string
    {
        return $this->getStringValue(AuthSessionKeys::PENDING_MFA_SECRET);
    }

    public function clearPendingMfaSetup(): void
    {
        $this->session()->remove(AuthSessionKeys::PENDING_MFA_SECRET);
        $this->session()->remove(AuthSessionKeys::MFA_SETUP_EMAIL_SECRET);
    }

    public function storeJwtToken(?string $token): void
    {
        $this->setStringValue(AuthSessionKeys::JWT_TOKEN, $token);
    }

    public function getStoredJwtToken(): ?string
    {
        return $this->getStringValue(AuthSessionKeys::JWT_TOKEN);
    }

    public function getMfaSetupEmailSessionKey(): string
    {
        return AuthSessionKeys::MFA_SETUP_EMAIL_SECRET;
    }

    private function setAuthenticatedUserId(int $userId): void
    {
        $this->session()->set(AuthSessionKeys::AUTHENTICATED_USER_ID, $userId);
    }

    private function clearPendingAuthentication(): void
    {
        $this->session()->remove(AuthSessionKeys::PENDING_USER_ID);
        $this->session()->remove(AuthSessionKeys::PENDING_LOGIN_EMAIL);
        $this->session()->remove(AuthSessionKeys::PENDING_LOGIN_TOTP);
        $this->session()->remove(AuthSessionKeys::LOGIN_MFA_REQUIRED);
    }

    private function setStringValue(string $key, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $this->session()->remove($key);
            return;
        }

        $this->session()->set($key, $value);
    }

    private function getStringValue(string $key): ?string
    {
        $value = $this->session()->get($key);
        if (is_string($value) === false || $value === '') {
            return null;
        }

        return $value;
    }

    public function rotateCsrfToken(): void
    {
        $csrf = $this->application->get('csrfService');
        if ($csrf instanceof CsrfService) {
            $csrf->rotateToken();
        }
    }

    private function session(): SessionService
    {
        return $this->application->get('sessionService');
    }
}
