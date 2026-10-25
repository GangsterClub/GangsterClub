<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;

final class AccountRecoveryCodesController extends Controller
{
    public function startEnrollment(Request $request): Response
    {
        return (new RecoveryStartHandler($this->application))->startEnrollment($request);
    }

    public function startInitial(Request $request): Response
    {
        return (new RecoveryStartHandler($this->application))->startInitial($request);
    }

    public function startReplacement(Request $request): Response
    {
        return (new RecoveryStartHandler($this->application))->startReplacement($request);
    }

    public function account(Request $request): Response
    {
        return (new RecoveryFlowHandler($this->application))->account($request);
    }
}
