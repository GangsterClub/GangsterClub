<?php

declare(strict_types=1);

namespace src\Identity\AccountSecurity\Application;

use src\Business\AuthenticatorTOTPService;
use src\Business\UserService;

final class UpdateTwoStepPreference
{
    public function __construct(
        private readonly AuthenticatorTOTPService $authenticator,
        private readonly UserService $users
    ) {
    }

    public function update(int $userId, bool $required): TwoStepPreferenceOutcome
    {
        if ($required === true && $this->authenticator->hasEnabledAuthenticator($userId) === false) {
            return TwoStepPreferenceOutcome::AUTHENTICATOR_REQUIRED;
        }
        if ($this->users->setTwoStepVerificationRequired($userId, $required) === false) {
            return TwoStepPreferenceOutcome::ERROR;
        }
        return $required === true
            ? TwoStepPreferenceOutcome::ENABLED
            : TwoStepPreferenceOutcome::DISABLED;
    }
}
