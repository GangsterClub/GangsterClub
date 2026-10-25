<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;

final class RegistrationController extends AuthEntryController
{
    public function register(Request $request): Response
    {
        return $this->handle($request, 'register');
    }
}
