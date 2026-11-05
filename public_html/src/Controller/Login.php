<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use src\Identity\Authentication\Http\AuthEntryEndpoint;

final class Login
{
    public function __construct(private readonly AuthEntryEndpoint $endpoint)
    {
    }

    public function login(Request $request): Response
    {
        return $this->endpoint->login($request);
    }
}
