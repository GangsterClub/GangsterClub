<?php

declare(strict_types=1);

namespace src\Identity\AccountSecurity\Application;

use app\Service\AuthService;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticatorTOTPService;
use src\Business\AuthenticatorTOTPPurpose;
use src\Business\RateLimitExceededException;
use src\Business\RecoveryFeatureService;

final class DisableAuthenticator
{
    public function __construct(
        private readonly AuthenticatorTOTPService $authenticator,
        private readonly RecoveryFeatureService $recovery,
        private readonly SessionService $session,
        private readonly SecurityFlowSession $securityFlow,
        private readonly AuthService $auth
    ) {
    }

    public function disable(int $userId, string $submittedCode): AuthenticatorDisableOutcome
    {
        $code = preg_replace('/\s+/', '', $submittedCode) ?? '';
        if (preg_match('/^\d{' . AUTHENTICATOR_TOTP_DIGITS . '}$/', $code) !== 1) {
            return AuthenticatorDisableOutcome::CODE_REQUIRED;
        }

        try {
            $verified = $this->authenticator->verify(
                $userId,
                AuthenticatorTOTPPurpose::AUTHENTICATOR_DISABLE,
                $code,
                AuthenticationRateLimitContext::forUser(
                    $userId,
                    (string) $this->session->get('_IPaddress', '127.0.0.1'),
                    $this->securityFlow->getChallengeBinding()
                )
            );
        } catch (RateLimitExceededException) {
            return AuthenticatorDisableOutcome::INVALID_CODE;
        }

        if ($verified === false) {
            return AuthenticatorDisableOutcome::INVALID_CODE;
        }
        if ($this->recovery->disableAuthenticatorAndRecoveryCodes($userId) === false) {
            return AuthenticatorDisableOutcome::ERROR;
        }

        $this->securityFlow->setPendingAuthenticatorSecret(null);
        $this->auth->rotateCsrfToken();
        return AuthenticatorDisableOutcome::DISABLED;
    }
}
