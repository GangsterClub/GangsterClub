<?php

declare(strict_types=1);

namespace src\Controller\AuthEntry;

use app\Http\Request;
use app\Service\AuthService;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthEntryService;
use src\Business\RecoveryFeatureService;

final class AuthEntryRequestHandler
{
    public function __construct(
        private readonly AuthEntryService $entries,
        private readonly AuthService $auth,
        private readonly PendingAuthenticationSession $pending,
        private readonly SecurityFlowSession $securityFlow,
        private readonly SessionService $session,
        private readonly RecoveryFeatureService $recoveryFeature,
        private readonly RegistrationFormState $registrationForm
    ) {}

    public function firstStep(Request $request, string $mode): ?array
    {
        return $mode === AuthEntryService::MODE_LOGIN
            ? $this->loginFirstStep($request)
            : $this->registrationFirstStep($request);
    }

    public function verification(Request $request, string $mode): ?array
    {
        if ($mode === AuthEntryService::MODE_LOGIN && $request->post('submit_recovery_code') !== null) {
            $userId = $this->pending->getUserId();
            if ($userId === null) {
                return ['status' => AuthEntryService::STATUS_INVALID_RECOVERY_CODE];
            }
            if ($this->recoveryFeature->getActiveRecoverySetId($userId) === null) {
                return ['status' => AuthEntryService::STATUS_RECOVERY_CODE_UNAVAILABLE];
            }
            return $this->entries->verifyRecoveryCode(
                $this->auth,
                (string) $request->post('recovery_code', ''),
                AuthenticationRateLimitContext::forUser(
                    $userId,
                    (string) $this->session->get('_IPaddress', '127.0.0.1'),
                    $this->securityFlow->getChallengeBinding()
                )
            );
        }

        $submit = $request->post('submit_totp');
        $otp = $request->post('totp');
        if ((bool) $submit !== true || (bool) $otp !== true) {
            return null;
        }
        $otp = is_array($otp) === true ? implode('', $otp) : (string) $otp;
        try {
            return $this->entries->verify($this->auth, $mode, $otp);
        } catch (\src\Business\RateLimitExceededException) {
            return ['status' => AuthEntryService::STATUS_INVALID_OTP];
        }
    }

    private function loginFirstStep(Request $request): ?array
    {
        if ((bool) $request->post('submit_login') !== true || (bool) $request->post('email') !== true) {
            return null;
        }
        try {
            return $this->entries->beginLogin($this->auth, (string) $request->post('email'));
        } catch (\src\Business\RateLimitExceededException) {
            return ['status' => AuthEntryService::STATUS_EMAIL_OTP_SENT];
        }
    }

    private function registrationFirstStep(Request $request): ?array
    {
        if ((bool) $request->post('submit_register') !== true) {
            return null;
        }
        $username = (string) $request->post('username');
        $email = (string) $request->post('email');
        $result = $this->entries->beginRegistration($this->auth, $username, $email);
        if (in_array($result['status'] ?? null, [
            AuthEntryService::STATUS_VALIDATION_ERROR,
            AuthEntryService::STATUS_SEND_ERROR,
        ], true) === true) {
            $this->registrationForm->remember($username, $email);
        } else {
            $this->registrationForm->forget();
        }
        return $result;
    }
}
