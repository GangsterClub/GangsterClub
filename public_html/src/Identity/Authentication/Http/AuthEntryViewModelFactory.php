<?php

declare(strict_types=1);

namespace src\Identity\Authentication\Http;

use app\Service\AuthService;
use app\Service\PendingAuthenticationSession;
use src\Business\AuthEntryService;
use src\Business\RecoveryFeatureService;
use src\Identity\Authentication\Infrastructure\SessionRegistrationFormState;

final class AuthEntryViewModelFactory
{
    public function __construct(
        private readonly PendingAuthenticationSession $pending,
        private readonly RecoveryFeatureService $recoveryFeature,
        private readonly SessionRegistrationFormState $registrationForm
    ) {
    }

    public function build(AuthService $auth, string $mode): array
    {
        $email = $this->pending->getEmail();
        $registerValues = $mode === AuthEntryService::MODE_REGISTER && $email === null
            ? $this->registrationForm->consume()
            : [];
        $userId = $this->pending->getUserId();
        $totp = $this->pending->getTotp();

        return [
            'email' => $email ?? ($registerValues['email'] ?? null),
            'registerUsername' => $registerValues['username'] ?? '',
            'usernamePattern' => str_replace('_-', '_\-', AuthEntryService::REGISTRATION_USERNAME_PATTERN),
            'awaitingOtp' => $email !== null,
            'uUID' => $userId,
            'totp' => is_string($totp) === true ? str_split($totp) : [],
            'UID' => $auth->getAuthenticatedUserId(),
            'loginRecoveryAvailable' => $mode === AuthEntryService::MODE_LOGIN
                && $this->pending->isAuthenticatorRequired()
                && $userId !== null
                && $this->recoveryFeature->getActiveRecoverySetId($userId) !== null,
        ];
    }
}
