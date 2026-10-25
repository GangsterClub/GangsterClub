<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;

final class LostAuthenticatorRecoveryController extends Controller
{
    public function start(Request $request): Response
    {
        return (new RecoveryStartHandler($this->application))->startLostAuthenticator($request);
    }

    public function recovery(Request $request): Response
    {
        return (new RecoveryFlowHandler($this->application))->lostAuthenticator($request);
    }
}
