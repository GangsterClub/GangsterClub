<?php

declare(strict_types=1);

namespace src\Controller\AuthEntry;

use app\Http\Response;
use app\Http\Router;
use app\Service\FlashBag;
use src\Business\AuthEntryService;
use src\Business\RecoveryCodeConsumptionResult;

final class AuthEntryResultResponder
{
    private const LOGIN_RECOVERY_FLASH_BAG = 'login.recovery';

    public function __construct(private readonly FlashBag $flash) {}

    public function firstStep(string $mode, array $result): Response
    {
        match ($result['status'] ?? null) {
            AuthEntryService::STATUS_AUTHENTICATOR_CODE_REQUIRED => $this->flash->add('login', 'success', __('login.authenticator-app-instructions', [
                'digits' => (string) AUTHENTICATOR_TOTP_DIGITS,
                'period' => (string) AUTHENTICATOR_TOTP_PERIOD,
            ])),
            AuthEntryService::STATUS_EMAIL_OTP_SENT => $this->flash->add($mode, 'success', $this->translate($mode, 'otp-email-sent')),
            AuthEntryService::STATUS_SEND_ERROR => $this->flash->add($mode, 'errors', $this->translate($mode, 'error-email')),
            AuthEntryService::STATUS_VALIDATION_ERROR => $this->flash->add($mode, 'errors', $this->validationError($result['error'] ?? '', $mode)),
            default => null,
        };
        return $this->redirectSelf();
    }

    public function verification(string $mode, array $result): Response
    {
        $status = $result['status'] ?? null;
        if ($status === AuthEntryService::STATUS_AUTHENTICATOR_CODE_REQUIRED) {
            $this->flash->add($mode, 'success', __('login.authenticator-app-instructions', [
                'digits' => (string) AUTHENTICATOR_TOTP_DIGITS,
                'period' => (string) AUTHENTICATOR_TOTP_PERIOD,
            ]));
            return $this->redirectSelf();
        }
        if ($status === AuthEntryService::STATUS_AUTHENTICATED) {
            $this->flash->add('account', 'success', $this->translate($mode, 'success-authenticated'));
            $remaining = $result['remainingCount'] ?? null;
            if (is_int($remaining) === true && $remaining <= 3) {
                $key = $remaining <= 1 ? 'recovery-code-one-remaining' : 'recovery-code-low-count';
                $this->flash->add('account', 'success', __('login.' . $key, ['count' => (string) $remaining]));
            }
            return Response::redirect(Router::path('account'), 303);
        }

        $this->flashVerificationFailure($mode, $status, $result);
        return $this->redirectSelf();
    }

    private function flashVerificationFailure(string $mode, mixed $status, array $result): void
    {
        match ($status) {
            RecoveryCodeConsumptionResult::STATUS_RATE_LIMITED => $this->flash->add(self::LOGIN_RECOVERY_FLASH_BAG, 'errors', __('login.recovery-code-rate-limited', [
                'seconds' => (string) ($result['retryAfterSeconds'] ?? 0),
            ])),
            AuthEntryService::STATUS_INVALID_RECOVERY_CODE => $this->flash->add(self::LOGIN_RECOVERY_FLASH_BAG, 'errors', __('login.recovery-code-invalid')),
            AuthEntryService::STATUS_RECOVERY_CODE_UNAVAILABLE => $this->flash->add($mode, 'errors', __('login.recovery-code-unavailable')),
            default => $this->flash->add($mode, 'errors', $this->translate($mode, 'error-invalid-otp')),
        };
    }

    private function validationError(string $error, string $mode): string
    {
        return match ($error) {
            'provide-valid-email' => $this->translate($mode, 'provide-valid-email-address'),
            'provide-valid-username' => __('provide-valid-username'),
            'duplicate-email' => __('email-address-already-in-use'),
            'duplicate-username' => __('username-already-in-use'),
            default => $this->translate($mode, 'error-email'),
        };
    }

    private function translate(string $mode, string $key): string
    {
        return $mode === AuthEntryService::MODE_REGISTER ? __('login.' . $key) : __($key);
    }

    private function redirectSelf(): Response
    {
        return Response::redirect((string) (REQUEST_URI ?? Router::path('home')), 303);
    }
}
