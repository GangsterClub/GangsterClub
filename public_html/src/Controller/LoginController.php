<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;

final class LoginController extends AuthEntryController
{
    public function login(Request $request): Response
    {
        return $this->handle($request, 'login');
    }
}
