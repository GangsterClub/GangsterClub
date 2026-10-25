<?php

declare(strict_types=1);

namespace app\Service;

use src\Data\Repository\UserRepository;

class AuthService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly CsrfService $csrfService,
        private readonly ?UserRepository $userRepository = null,
        ?PendingAuthenticationSession $pendingAuthentication = null,
        ?SecurityFlowSession $securityFlow = null
    ) {
        $this->pendingAuthentication = $pendingAuthentication ?? new PendingAuthenticationSession($sessionService);
        $this->securityFlow = $securityFlow ?? new SecurityFlowSession($sessionService);
    }

    private readonly PendingAuthenticationSession $pendingAuthentication;
    private readonly SecurityFlowSession $securityFlow;

    public function loginUser(int $userId): void
    {
        $this->sessionService->regenerate();
        $this->setAuthenticatedUserId($userId);
        $this->storeCurrentBrowserSessionVersion($userId);
        $this->pendingAuthentication->clear();
        $this->rotateCsrfToken();
    }

    public function logoutUser(bool $regenerateSession = true): void
    {
        foreach ([
            AuthSessionKeys::AUTHENTICATED_USER_ID,
            AuthSessionKeys::AUTHENTICATOR_SETUP_EMAIL_SECRET,
            AuthSessionKeys::SECURITY_CHALLENGE_BINDING,
            AuthSessionKeys::BROWSER_SESSION_VERSION,
            AuthSessionKeys::JWT_TOKEN,
        ] as $key) {
            $this->sessionService->remove($key);
        }

        $this->pendingAuthentication->clear();
        $this->securityFlow->clear();

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

    private function setAuthenticatedUserId(int $userId): void
    {
        $this->sessionService->set(AuthSessionKeys::AUTHENTICATED_USER_ID, $userId);
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
