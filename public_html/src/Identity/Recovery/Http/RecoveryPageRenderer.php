<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Http;

use app\Http\Response;
use app\Service\FlashBag;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;
use src\Identity\Recovery\Application\RecoveryFlowState;
use src\Identity\Recovery\Application\RecoveryMode;
use src\Identity\Recovery\Application\RecoveryResult;

final class RecoveryPageRenderer
{
    public function __construct(
        private readonly \Twig\Environment $twig,
        private readonly AuthenticatorTOTPService $authenticators,
        private readonly RecoveryFeatureService $recovery,
        private readonly RecoveryFlowState $flow,
        private readonly FlashBag $flash
    ) {
    }

    public function render(RecoveryResult $result): Response
    {
        $user = $result->user;
        $challenge = $result->challenge;
        if ($user === null || $challenge === null) {
            throw new \LogicException('Recovery page rendering requires a user and challenge.');
        }

        $snapshot = $this->flow->snapshot();
        $pendingSecret = $snapshot->pendingAuthenticatorSecret;
        $showConfiguration = in_array((string) $challenge->state, [
            'authenticator_configuration_presented',
            'new_authenticator_configuration_presented',
        ], true);
        $label = APP_NAME . ':' . $user->getEmail();

        return Response::html($this->twig->render('recovery-flow.twig', [
            'flow' => [
                'purpose' => (string) $challenge->purpose,
                'state' => (string) $challenge->state,
                'lost' => $result->mode === RecoveryMode::LOST_AUTHENTICATOR,
                'codes' => $result->codes,
                'pendingSetId' => $snapshot->pendingRecoverySetId,
                'remainingCount' => $this->recovery->getUnusedRecoveryCodeCount($user->getId()),
                'authenticatorSecret' => $showConfiguration === true ? $pendingSecret : null,
                'otpauth' => $this->provisioningUri($showConfiguration, $pendingSecret, $label),
                'qrCodeUrl' => $this->qrCode($showConfiguration, $pendingSecret, $label),
                'digits' => (int) AUTHENTICATOR_TOTP_DIGITS,
                'emailDigits' => (int) TOTP_DIGITS,
            ],
            'user' => $user,
            'recovery' => $this->flash->consume('recovery.' . (string) $challenge->purpose),
        ]), 200, RecoveryResponseMapper::NO_STORE_HEADERS);
    }

    private function provisioningUri(bool $show, ?string $secret, string $label): ?string
    {
        return $show === true && $secret !== null
            ? $this->authenticators->generateProvisioningUri($secret, $label) : null;
    }

    private function qrCode(bool $show, ?string $secret, string $label): ?string
    {
        return $show === true && $secret !== null
            ? $this->authenticators->generateQRCode($secret, $label) : null;
    }
}
