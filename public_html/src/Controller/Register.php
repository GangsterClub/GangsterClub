<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use src\Identity\Authentication\Http\AuthEntryEndpoint;

final class Register
{
    public function __construct(private readonly AuthEntryEndpoint $endpoint)
    {
    }

    public function register(Request $request): Response
    {
        return $this->endpoint->register($request);
    }
}
