<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use src\Identity\AccountSecurity\Http\AuthenticatorDisableEndpoint;

final class AuthenticatorSecurity
{
    public function __construct(private readonly AuthenticatorDisableEndpoint $endpoint)
    {
    }

    public function startDisable(Request $request): Response
    {
        return $this->endpoint->start($request);
    }

    public function disable(Request $request): Response
    {
        return $this->endpoint->disable($request);
    }
}
