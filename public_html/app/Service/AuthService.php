<?php

declare(strict_types=1);

namespace app\Service;

use src\Data\Repository\UserRepository;

class AuthService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly CsrfService $csrfService,
        private readonly ?UserRepository $userRepository = null
    ) {
    }

    public function loginUser(int $userId): void
    {
        $this->sessionService->regenerate();
        $this->setAuthenticatedUserId($userId);
        $this->storeCurrentBrowserSessionVersion($userId);
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
            AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED,
            AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET,
            AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET,
            AuthSessionKeys::SECURITY_CHALLENGE_TOKEN,
            AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE,
            AuthSessionKeys::SECURITY_CHALLENGE_BINDING,
            AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID,
            AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED,
            AuthSessionKeys::SECURITY_EMAIL_SECRET,
            AuthSessionKeys::BROWSER_SESSION_VERSION,
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
        if ($userId <= 0) {
            return null;
        }

        if ($this->browserSessionIsCurrent($userId) === false) {
            $this->logoutUser(false);
            return null;
        }

        return $userId;
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

    public function setLoginTwoStepRequired(bool $required): void
    {
        $this->sessionService->set(AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED, $required);
    }

    public function isLoginTwoStepRequired(): bool
    {
        return $this->sessionService->get(AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED, false) === true;
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

    public function getSecurityEmailSessionKey(): string
    {
        return AuthSessionKeys::SECURITY_EMAIL_SECRET;
    }

    public function getSecurityChallengeBinding(): string
    {
        $binding = $this->getStringValue(AuthSessionKeys::SECURITY_CHALLENGE_BINDING);
        if ($binding === null) {
            $binding = bin2hex(random_bytes(32));
            $this->sessionService->set(AuthSessionKeys::SECURITY_CHALLENGE_BINDING, $binding);
        }

        return $binding;
    }

    public function setSecurityChallenge(string $token, string $purpose): void
    {
        $this->sessionService->set(AuthSessionKeys::SECURITY_CHALLENGE_TOKEN, $token);
        $this->sessionService->set(AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE, $purpose);
    }

    public function getSecurityChallengeToken(): ?string
    {
        return $this->getStringValue(AuthSessionKeys::SECURITY_CHALLENGE_TOKEN);
    }

    public function getSecurityChallengePurpose(): ?string
    {
        return $this->getStringValue(AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE);
    }

    public function setPendingRecoverySetId(?int $setId): void
    {
        if ($setId === null || $setId <= 0) {
            $this->sessionService->remove(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID);
            return;
        }

        $this->sessionService->set(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID, $setId);
    }

    public function getPendingRecoverySetId(): ?int
    {
        $setId = (int) $this->sessionService->get(AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID, 0);
        return $setId > 0 ? $setId : null;
    }

    public function markRecoveryCodesDelivered(int $setId): void
    {
        if ($setId <= 0) {
            throw new \InvalidArgumentException('A valid delivered recovery-code set is required.');
        }

        $this->sessionService->set(AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED, $setId);
    }

    public function getDeliveredRecoverySetId(): ?int
    {
        $setId = (int) $this->sessionService->get(AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED, 0);
        return $setId > 0 ? $setId : null;
    }

    public function clearSecurityFlow(): void
    {
        foreach ([
            AuthSessionKeys::SECURITY_CHALLENGE_TOKEN,
            AuthSessionKeys::SECURITY_CHALLENGE_PURPOSE,
            AuthSessionKeys::SECURITY_PENDING_RECOVERY_SET_ID,
            AuthSessionKeys::SECURITY_RECOVERY_CODES_DELIVERED,
            AuthSessionKeys::SECURITY_EMAIL_SECRET,
            AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET,
        ] as $key) {
            $this->sessionService->remove($key);
        }
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
        $this->sessionService->remove(AuthSessionKeys::LOGIN_TWO_STEP_REQUIRED);
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

    private function storeCurrentBrowserSessionVersion(int $userId): void
    {
        if ($this->userRepository === null) {
            return;
        }

        $version = $this->userRepository->getBrowserSessionVersion($userId);
        if ($version !== null) {
            $this->sessionService->set(AuthSessionKeys::BROWSER_SESSION_VERSION, $version);
        }
    }

    private function browserSessionIsCurrent(int $userId): bool
    {
        if ($this->userRepository === null) {
            return true;
        }

        $currentVersion = $this->userRepository->getBrowserSessionVersion($userId);
        if ($currentVersion === null) {
            return false;
        }

        $sessionVersion = $this->sessionService->get(AuthSessionKeys::BROWSER_SESSION_VERSION);
        if ($sessionVersion === null || $sessionVersion === '') {
            return false;
        }

        return (int) $sessionVersion === $currentVersion;
    }
}
