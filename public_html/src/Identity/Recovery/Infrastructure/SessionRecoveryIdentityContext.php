<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Infrastructure;

use app\Service\AuthService;
use app\Service\PendingAuthenticationSession;
use src\Identity\Recovery\Application\RecoveryIdentityContext;

final class SessionRecoveryIdentityContext implements RecoveryIdentityContext
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PendingAuthenticationSession $pending
    ) {
    }

    public function authenticatedUserId(): ?int { return $this->auth->getAuthenticatedUserId(); }
    public function pendingUserId(): ?int { return $this->pending->getUserId(); }
    public function pendingAuthenticatorRequired(): bool { return $this->pending->isAuthenticatorRequired(); }
    public function login(int $userId): void { $this->auth->loginUser($userId); }
    public function rotateCsrfToken(): void { $this->auth->rotateCsrfToken(); }
}
