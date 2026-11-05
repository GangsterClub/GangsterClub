<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Http;

use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\FlashBag;
use app\Service\TranslationService;
use src\Identity\Recovery\Application\RecoveryCeremony;
use src\Identity\Recovery\Application\RecoveryMode;
use src\Identity\Recovery\Application\RecoveryOutcome;
use src\Identity\Recovery\Application\RecoveryResult;

final class RecoveryResponseMapper
{
    public const NO_STORE_HEADERS = [
        'Cache-Control: no-store, private',
        'Pragma: no-cache',
        'Referrer-Policy: no-referrer',
    ];

    private const DEFINITIONS = [
        'action_unavailable' => [false, 'recovery.action-unavailable', 200, 'account', 'account'],
        'authenticator_already_enabled' => [false, 'recovery.authenticator-already-enabled', 200, 'account', 'account'],
        'lost_recovery_unavailable' => [false, 'recovery.lost-replacement-unavailable', 200, 'login', 'login'],
        'start_rate_limited' => [false, 'recovery.rate-limited', 429, 'home', 'home'],
        'flow_rate_limited' => [false, 'recovery.rate-limited', 429, 'flow', 'purpose'],
        'email_send_failed' => [false, 'recovery.email-send-failed', 200, 'home', 'home'],
        'email_verification_pending' => [true, 'recovery.email-code-sent', 200, 'flow', 'purpose'],
        'reauthentication_pending' => [true, 'recovery.reauthentication-required', 200, 'flow', 'purpose'],
        'flow_unavailable' => [false, 'recovery.no-active-flow', 409, 'home', 'home'],
        'flow_expired' => [false, 'recovery.challenge-expired', 409, 'home', 'home'],
        'pending_secret_missing' => [false, 'recovery.pending-authenticator-secret-lost', 409, 'home', 'home'],
        'invalid_action' => [false, 'recovery.invalid-action', 200, 'flow', 'purpose'],
        'email_code_invalid' => [false, 'recovery.email-code-invalid', 200, 'flow', 'purpose'],
        'enter_recovery_code' => [true, 'recovery.enter-active-recovery-code', 200, 'flow', 'purpose'],
        'configure_authenticator' => [true, 'recovery.configure-authenticator', 200, 'flow', 'purpose'],
        'authenticator_code_invalid' => [false, 'recovery.authenticator-code-invalid', 200, 'flow', 'purpose'],
        'replacement_warning' => [true, 'recovery.replacement-warning', 200, 'flow', 'purpose'],
        'lost_replacement_warning' => [true, 'recovery.lost-replacement-warning', 200, 'flow', 'purpose'],
        'recovery_code_invalid' => [false, 'recovery.recovery-code-invalid', 200, 'flow', 'purpose'],
        'confirm_replacement_required' => [false, 'recovery.confirm-replacement-required', 200, 'flow', 'purpose'],
        'configure_new_authenticator' => [true, 'recovery.configure-new-authenticator', 200, 'flow', 'purpose'],
        'reauthentication_expired' => [false, 'recovery.reauthentication-expired', 200, 'flow', 'purpose'],
        'challenge_expired' => [false, 'recovery.challenge-expired', 200, 'flow', 'purpose'],
        'codes_generated' => [true, 'recovery.codes-generated', 200, 'flow', 'purpose'],
        'acknowledgement_required' => [false, 'recovery.acknowledgement-required', 200, 'flow', 'purpose'],
        'codes_display_unavailable' => [false, 'recovery.codes-display-unavailable', 200, 'flow', 'purpose'],
        'activation_failed' => [false, 'recovery.activation-failed', 200, 'flow', 'purpose'],
        'completed' => [true, 'recovery.completed', 200, 'account', 'account'],
        'previous_codes_invalid' => [true, 'recovery.previous-codes-invalid', 200, 'account', 'account'],
        'codes_regenerated' => [true, 'recovery.codes-regenerated', 200, 'flow', 'purpose'],
        'cancelled' => [true, 'recovery.cancelled', 200, 'home', 'home'],
    ];

    public function __construct(
        private readonly FlashBag $flash,
        private readonly TranslationService $translation,
        private readonly RecoveryPageRenderer $renderer
    ) {
    }

    public function prepareStart(): void
    {
        foreach ([
            'recovery',
            'recovery.' . RecoveryCeremony::ENROLLMENT->value,
            'recovery.' . RecoveryCeremony::LOST_AUTHENTICATOR->value,
            'recovery.' . RecoveryCeremony::INITIAL_CODES->value,
            'recovery.' . RecoveryCeremony::REPLACEMENT_CODES->value,
        ] as $bag) {
            $this->flash->consume($bag);
        }
    }

    public function prepareFlow(): void
    {
        $this->translation->setFile('recovery');
    }

    public function map(Request $request, RecoveryResult $result): Response
    {
        if ($result->outcome === RecoveryOutcome::UNAUTHENTICATED) {
            return Response::redirect(Router::path('login'), 303);
        }
        if ($result->challenge !== null || in_array($result->outcome, [
            RecoveryOutcome::FLOW_EXPIRED,
            RecoveryOutcome::PENDING_SECRET_MISSING,
        ], true) === true) {
            $this->flash->consume('recovery');
        }
        $this->addLowCodeNotice($result);
        if ($this->shouldRender($request, $result) === true) {
            return $this->renderer->render($result);
        }

        $definition = self::DEFINITIONS[$result->outcome->value] ?? null;
        if (is_array($definition) === false) {
            throw new \LogicException('Missing recovery HTTP response definition.');
        }
        return $this->mappedResponse($request, $result, $definition);
    }

    /** @param array{0: bool, 1: string, 2: int, 3: string, 4: string} $definition */
    private function mappedResponse(Request $request, RecoveryResult $result, array $definition): Response
    {
        [$success, $messageKey, $status, $redirectTarget, $flashTarget] = $definition;
        $message = __($messageKey);
        $redirect = Router::path($this->target($redirectTarget, $result, true));
        if ($this->expectsJson($request) === true) {
            $payload = [
                'success' => $success,
                'state' => $result->state,
                'nextStep' => $result->state,
                'message' => $message,
                'errors' => $success === true ? [] : [$message],
                'redirect' => $redirect,
            ];
            return Response::json(
                $this->withResultData($payload, $result),
                $success === true ? $status : max($status, 422),
                self::NO_STORE_HEADERS
            );
        }

        $this->flash->add(
            $this->target($flashTarget, $result, false),
            $success === true ? 'success' : 'errors',
            $message
        );
        return Response::redirect($redirect, 303);
    }

    private function target(string $target, RecoveryResult $result, bool $route): string
    {
        return match ($target) {
            'account' => 'account',
            'login' => 'login',
            'home' => $result->mode === RecoveryMode::ACCOUNT ? 'account' : 'login',
            default => $route === true
                ? ($result->mode === RecoveryMode::ACCOUNT ? 'accountRecoveryCodes' : 'loginRecovery')
                : 'recovery.' . (string) $result->purpose,
        };
    }

    private function withResultData(array $payload, RecoveryResult $result): array
    {
        if ($result->retryAfterSeconds !== null) {
            $payload['retryAfterSeconds'] = $result->retryAfterSeconds;
        }
        if ($result->remainingCount !== null) {
            $payload['remainingCount'] = $result->remainingCount;
        }
        if ($result->codes !== null) {
            $payload['codes'] = $result->codes;
        }
        return $payload;
    }

    private function shouldRender(Request $request, RecoveryResult $result): bool
    {
        return $result->outcome === RecoveryOutcome::SHOW_FLOW
            || ($this->expectsJson($request) === false && in_array($result->outcome, [
                RecoveryOutcome::CODES_GENERATED,
                RecoveryOutcome::CODES_REGENERATED,
            ], true));
    }

    private function addLowCodeNotice(RecoveryResult $result): void
    {
        if ($result->outcome !== RecoveryOutcome::LOST_REPLACEMENT_WARNING
            || $result->remainingCount === null
            || $result->remainingCount > 3
        ) {
            return;
        }
        $key = $result->remainingCount <= 1 ? 'recovery.one-code-remaining' : 'recovery.low-code-count';
        $this->flash->add('recovery', 'success', __($key, ['count' => (string) $result->remainingCount]));
    }

    private function expectsJson(Request $request): bool
    {
        return str_contains(strtolower((string) $request->getHeader('Accept')), 'application/json')
            || strtolower((string) $request->getHeader('X-Requested-With')) === 'xmlhttprequest';
    }
}
