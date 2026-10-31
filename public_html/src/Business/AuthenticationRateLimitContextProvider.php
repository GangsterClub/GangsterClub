<?php

declare(strict_types=1);

namespace src\Business;

use app\Service\SessionService;

final class AuthenticationRateLimitContextProvider
{
    private const SESSION_IDENTIFIER = '_security_rate_limit_id';

    public function __construct(private readonly SessionService $session)
    {
    }

    public function forUser(int $userId): AuthenticationRateLimitContext
    {
        return AuthenticationRateLimitContext::forUser(
            $userId,
            $this->ipAddress(),
            $this->sessionIdentifier()
        );
    }

    public function forEmail(string $email): AuthenticationRateLimitContext
    {
        return AuthenticationRateLimitContext::forEmail(
            $email,
            $this->ipAddress(),
            $this->sessionIdentifier()
        );
    }

    private function ipAddress(): string
    {
        return (string) $this->session->get('_IPaddress', '0.0.0.0');
    }

    private function sessionIdentifier(): string
    {
        $identifier = (string) $this->session->get(self::SESSION_IDENTIFIER, '');
        if ($identifier === '') {
            $identifier = bin2hex(random_bytes(16));
            $this->session->set(self::SESSION_IDENTIFIER, $identifier);
        }
        return $identifier;
    }
}
