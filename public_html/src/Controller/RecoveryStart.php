<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use src\Identity\Recovery\Application\RecoveryCeremony;
use src\Identity\Recovery\Application\StartAccountRecovery;
use src\Identity\Recovery\Application\StartLostAuthenticatorRecovery;
use src\Identity\Recovery\Http\RecoveryResponseMapper;

final class RecoveryStart
{
    public function __construct(
        private readonly StartAccountRecovery $accountRecovery,
        private readonly StartLostAuthenticatorRecovery $lostAuthenticatorRecovery,
        private readonly RecoveryResponseMapper $responses
    ) {
    }

    public function enroll(Request $request): Response
    {
        return $this->startAccount($request, RecoveryCeremony::ENROLLMENT);
    }

    public function initial(Request $request): Response
    {
        return $this->startAccount($request, RecoveryCeremony::INITIAL_CODES);
    }

    public function replace(Request $request): Response
    {
        return $this->startAccount($request, RecoveryCeremony::REPLACEMENT_CODES);
    }

    public function lost(Request $request): Response
    {
        $this->responses->prepareStart();
        return $this->responses->map($request, $this->lostAuthenticatorRecovery->start());
    }

    private function startAccount(Request $request, RecoveryCeremony $ceremony): Response
    {
        $this->responses->prepareStart();
        return $this->responses->map($request, $this->accountRecovery->start($ceremony));
    }
}
