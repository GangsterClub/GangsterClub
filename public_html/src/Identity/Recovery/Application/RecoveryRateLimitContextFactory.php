<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

use src\Business\AuthenticationRateLimitContext;

interface RecoveryRateLimitContextFactory
{
    public function forChallenge(int $userId, int $challengeId): AuthenticationRateLimitContext;

    public function forToken(int $userId, string $token): AuthenticationRateLimitContext;
}
