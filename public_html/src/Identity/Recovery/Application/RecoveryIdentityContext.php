<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

interface RecoveryIdentityContext
{
    public function authenticatedUserId(): ?int;

    public function pendingUserId(): ?int;

    public function pendingAuthenticatorRequired(): bool;

    public function login(int $userId): void;

    public function rotateCsrfToken(): void;
}
