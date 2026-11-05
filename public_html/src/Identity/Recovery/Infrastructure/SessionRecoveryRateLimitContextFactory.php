<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Infrastructure;

use app\Service\SessionService;
use src\Business\AuthenticationRateLimitContext;
use src\Identity\Recovery\Application\RecoveryFlowState;
use src\Identity\Recovery\Application\RecoveryRateLimitContextFactory;

final class SessionRecoveryRateLimitContextFactory implements RecoveryRateLimitContextFactory
{
    public function __construct(
        private readonly SessionService $session,
        private readonly RecoveryFlowState $flow
    ) {
    }

    public function forChallenge(int $userId, int $challengeId): AuthenticationRateLimitContext
    {
        return $this->context($userId, (string) $challengeId);
    }

    public function forToken(int $userId, string $token): AuthenticationRateLimitContext
    {
        return $this->context($userId, $token);
    }

    private function context(int $userId, string $challenge): AuthenticationRateLimitContext
    {
        return AuthenticationRateLimitContext::forUser(
            $userId,
            (string) $this->session->get('_IPaddress', '127.0.0.1'),
            $this->flow->getChallengeBinding(),
            $challenge
        );
    }
}
