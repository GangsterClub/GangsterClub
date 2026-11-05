<?php

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use src\Identity\Recovery\Application\RecoveryAction;
use src\Identity\Recovery\Application\RecoveryActionInput;
use src\Identity\Recovery\Application\RecoveryFlowCoordinator;
use src\Identity\Recovery\Application\RecoveryMode;
use src\Identity\Recovery\Http\RecoveryResponseMapper;

final class RecoveryFlowController
{
    public function __construct(
        private readonly RecoveryFlowCoordinator $recovery,
        private readonly RecoveryResponseMapper $responses
    ) {
    }

    public function account(Request $request): Response
    {
        return $this->handle($request, RecoveryMode::ACCOUNT);
    }

    public function lost(Request $request): Response
    {
        return $this->handle($request, RecoveryMode::LOST_AUTHENTICATOR);
    }

    private function handle(Request $request, RecoveryMode $mode): Response
    {
        $this->responses->prepareFlow();
        $result = $this->recovery->handle(
            $mode,
            new RecoveryActionInput(
                RecoveryAction::tryFrom((string) $request->post('action', '')),
                (string) $request->post('email_code', ''),
                (string) $request->post('authenticator_code', ''),
                (string) $request->post('recovery_code', ''),
                $request->post('confirm_replacement') === '1',
                $request->post('saved_codes') === '1'
            ),
            strtoupper($request->getMethod()) === 'POST'
        );
        return $this->responses->map($request, $result);
    }
}
