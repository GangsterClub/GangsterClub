<?php

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\EmailTOTPPurpose;
use src\Business\RateLimitExceededException;
use src\Business\RecoveryCodeConsumptionResult;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryAuthenticatorVerificationResult;
use src\Business\RecoveryFeatureService;
use src\Business\RecoveryFlowChallengeResult;
use src\Business\RecoveryFlowService;
use src\Business\UserService;
use src\Entity\User;

final class RecoveryVerificationActions extends RecoveryControllerComponent
{
    public function verifyEmail(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        if ((string) $challenge->state !== 'email_verification_pending'
            || $this->emailTotp->verify(
                $user->getId(),
                $this->emailPurpose((string) $challenge->purpose),
                $this->normalizeOtp((string) $request->post('email_code', '')),
                $this->rateLimitContext($user->getId(), $challenge)
            ) === false
        ) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.email-code-invalid');
        }

        $purpose = (string) $challenge->purpose;
        $token = $this->securityFlow->getChallengeToken() ?? '';
        $binding = $this->securityFlow->getChallengeBinding();
        $next = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'email_verification_pending',
            'email_verified'
        );
        if ($next === null) {
            return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
        }

        if ((bool) $lostFlow === true) {
            $next = $this->challenges->transition(
                $token,
                $binding,
                $purpose,
                'email_verified',
                'recovery_code_pending'
            );
            return $this->respond(
                $request,
                $next !== null,
                'recovery_code_pending',
                $next !== null ? 'recovery.enter-active-recovery-code' : 'recovery.challenge-expired'
            );
        }

        $secret = $this->authenticators->generateSecret();
        $this->securityFlow->setPendingAuthenticatorSecret($secret);
        $configured = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'email_verified',
            'authenticator_configuration_presented'
        );

        return $this->respond(
            $request,
            $configured !== null,
            'authenticator_configuration_presented',
            $configured !== null ? 'recovery.configure-authenticator' : 'recovery.challenge-expired'
        );
    }


    private function emailPurpose(string $challengePurpose): EmailTOTPPurpose
    {
        return match ($challengePurpose) {
            AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT => EmailTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
            AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY => EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
            default => throw new \DomainException('The challenge does not support Email TOTP verification.'),
        };
    }


    public function verifyAuthenticator(
        Request $request,
        User $user,
        object $challenge,
        bool $lostFlow
    ): Response {
        $code = $this->normalizeOtp((string) $request->post('authenticator_code', ''));
        $token = $this->securityFlow->getChallengeToken() ?? '';
        $binding = $this->securityFlow->getChallengeBinding();
        $result = $this->flow->verifyAuthenticator(
            $user->getId(),
            $challenge,
            $lostFlow,
            $this->securityFlow->getPendingAuthenticatorSecret(),
            $code,
            $this->rateLimitContext($user->getId(), $challenge),
            $token,
            $binding
        );
        if ($result->status === RecoveryAuthenticatorVerificationResult::INVALID) {
            return $this->respond(
                $request,
                false,
                (string) $challenge->state,
                'recovery.authenticator-code-invalid'
            );
        }
        if ($result->status === RecoveryAuthenticatorVerificationResult::EXPIRED) {
            return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
        }
        if ($result->status === RecoveryAuthenticatorVerificationResult::WARNING_PRESENTED) {
            return $this->respond(
                $request,
                true,
                'replacement_warning_presented',
                'recovery.replacement-warning'
            );
        }
        if ($result->challenge === null || $result->expectedState === null) {
            return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
        }

        return (new RecoveryPresentationActions(
            $this->application,
            $this->flowRedirectName,
            $this->flowFlashBag
        ))->generateAndPresent(
            $request,
            $user,
            $result->challenge,
            $result->expectedState,
            $lostFlow
        );
    }


    public function verifyLostRecoveryCode(Request $request, User $user, object $challenge): Response
    {
        if ((string) $challenge->state !== 'recovery_code_pending') {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.invalid-action');
        }

        $context = $this->rateLimitContext($user->getId(), $challenge);
        $result = $this->recoveryCodes->consumeActiveCode(
            $user->getId(),
            (string) $request->post('recovery_code', ''),
            $context,
            AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY
        );
        if ($result->status === RecoveryCodeConsumptionResult::STATUS_RATE_LIMITED) {
            return $this->respond(
                $request,
                false,
                'recovery_code_pending',
                'recovery.rate-limited',
                null,
                429,
                ['retryAfterSeconds' => $result->retryAfterSeconds]
            );
        }
        if ($result->isConsumed() === false) {
            return $this->respond($request, false, 'recovery_code_pending', 'recovery.recovery-code-invalid');
        }

        $token = $this->securityFlow->getChallengeToken() ?? '';
        $binding = $this->securityFlow->getChallengeBinding();
        $purpose = AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY;
        $consumed = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'recovery_code_pending',
            'recovery_code_consumed'
        );
        $warning = $consumed === null ? null : $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'recovery_code_consumed',
            'replacement_warning_presented'
        );
        if ($warning === null) {
            return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
        }

        $this->email->sendSecurityNotification(
            $user->getEmail(),
            'Recovery code used',
            'A recovery code was used to begin replacing a lost authenticator. If this was not you, secure your account immediately.'
        );
        if (($result->remainingCount ?? RecoveryCodeService::CODE_COUNT) <= 3) {
            $this->flash(
                'recovery',
                'success',
                __(
                    ($result->remainingCount ?? 0) <= 1
                        ? 'recovery.one-code-remaining'
                        : 'recovery.low-code-count',
                    ['count' => (string) ($result->remainingCount ?? 0)]
                )
            );
        }

        return $this->respond(
            $request,
            true,
            'replacement_warning_presented',
            'recovery.lost-replacement-warning',
            null,
            200,
            ['remainingCount' => $result->remainingCount]
        );
    }


    public function confirmReplacement(
        Request $request,
        User $user,
        object $challenge,
        bool $lostFlow
    ): Response {
        if ((string) $challenge->state !== 'replacement_warning_presented'
            || $request->post('confirm_replacement') !== '1'
        ) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.confirm-replacement-required');
        }

        $token = $this->securityFlow->getChallengeToken() ?? '';
        $binding = $this->securityFlow->getChallengeBinding();
        $purpose = (string) $challenge->purpose;
        if ((bool) $lostFlow === true) {
            $secret = $this->authenticators->generateSecret();
            $this->securityFlow->setPendingAuthenticatorSecret($secret);
            $next = $this->challenges->transition(
                $token,
                $binding,
                $purpose,
                'replacement_warning_presented',
                'new_authenticator_configuration_presented'
            );
            return $this->respond(
                $request,
                $next !== null,
                'new_authenticator_configuration_presented',
                $next !== null ? 'recovery.configure-new-authenticator' : 'recovery.challenge-expired'
            );
        }

        if ($this->challenges->isFreshReauthentication($challenge, $purpose) === false) {
            return $this->respond($request, false, 'expired', 'recovery.reauthentication-expired');
        }
        $confirmed = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            'replacement_warning_presented',
            'replacement_confirmed'
        );
        if ($confirmed === null) {
            return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
        }

        return (new RecoveryPresentationActions(
            $this->application,
            $this->flowRedirectName,
            $this->flowFlashBag
        ))->generateAndPresent(
            $request,
            $user,
            $confirmed,
            'replacement_confirmed',
            false
        );
    }


    private function normalizeOtp(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }

}
