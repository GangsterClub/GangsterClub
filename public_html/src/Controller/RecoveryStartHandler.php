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

final class RecoveryStartHandler extends RecoveryControllerComponent
{
    public function startEnrollment(Request $request): Response
    {
        $purpose = AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT;
        $this->beginRecoveryPresentation($purpose);
        $auth = $this->auth();
        $user = $this->authenticatedUser($auth);
        if ($user === null) {
            return $this->unauthenticated();
        }
        if ($this->authenticators->hasEnabledAuthenticator($user->getId()) === true) {
            return $this->respond(
                $request,
                false,
                'already_enabled',
                'recovery.authenticator-already-enabled',
                Router::path('account'),
                200,
                [],
                'account'
            );
        }

        $this->cleanupAbandonedFlow($auth, $user->getId());
        $challenge = $this->challenges->start(
            $user->getId(),
            $purpose,
            $this->securityFlow->getChallengeBinding()
        );
        $this->securityFlow->setChallenge($challenge['token'], $challenge['purpose']);
        try {
            $issued = $this->emailTotp->issue(
                $user->getId(),
                EmailTOTPPurpose::AUTHENTICATOR_ENROLLMENT,
                $this->rateLimitContextForToken($user->getId(), $challenge['token'])
            );
        } catch (RateLimitExceededException $exception) {
            return $this->rateLimitedStart($request, $exception, 'account');
        }
        if ($this->email->sendEmailTOTP($user->getEmail(), $issued->code) === false) {
            $this->emailTotp->cancelIssued($issued);
            $this->challenges->cancel(
                $challenge['token'],
                $this->securityFlow->getChallengeBinding(),
                $challenge['purpose']
            );
            $this->securityFlow->clear();
            return $this->respond(
                $request,
                false,
                'send_failed',
                'recovery.email-send-failed',
                Router::path('account'),
                200,
                [],
                'account'
            );
        }

        return $this->respond($request, true, 'email_verification_pending', 'recovery.email-code-sent');
    }


    public function startInitial(Request $request): Response
    {
        return $this->startAuthenticatedRecoveryCeremony(
            $request,
            AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES,
            false
        );
    }


    public function startReplacement(Request $request): Response
    {
        return $this->startAuthenticatedRecoveryCeremony(
            $request,
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES,
            true
        );
    }


    public function startLostAuthenticator(Request $request): Response
    {
        $purpose = AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY;
        $this->beginRecoveryPresentation($purpose);
        $auth = $this->auth();
        $userId = $this->pendingAuthentication->getUserId();
        if ($userId === null
            || $this->pendingAuthentication->isAuthenticatorRequired() === false
            || $this->feature->getActiveRecoverySetId($userId) === null
        ) {
            return $this->respond(
                $request,
                false,
                'unavailable',
                'recovery.lost-replacement-unavailable',
                Router::path('login'),
                200,
                [],
                'login'
            );
        }

        $user = $this->users->getUserById($userId);
        $generation = $this->feature->getAuthenticatorGeneration($userId);
        $activeSetId = $this->feature->getActiveRecoverySetId($userId);
        if ($user === null || $generation === null || $activeSetId === null) {
            return $this->respond(
                $request,
                false,
                'unavailable',
                'recovery.lost-replacement-unavailable',
                Router::path('login'),
                200,
                [],
                'login'
            );
        }

        $this->cleanupAbandonedFlow($auth, $userId);
        $challenge = $this->challenges->start(
            $userId,
            $purpose,
            $this->securityFlow->getChallengeBinding(),
            $generation,
            $activeSetId
        );
        $this->securityFlow->setChallenge($challenge['token'], $challenge['purpose']);
        try {
            $issued = $this->emailTotp->issue(
                $userId,
                EmailTOTPPurpose::LOST_AUTHENTICATOR_RECOVERY,
                $this->rateLimitContextForToken($userId, $challenge['token'])
            );
        } catch (RateLimitExceededException $exception) {
            return $this->rateLimitedStart($request, $exception, 'login');
        }
        if ($this->email->sendEmailTOTP($user->getEmail(), $issued->code) === false) {
            $this->emailTotp->cancelIssued($issued);
            $this->challenges->cancel(
                $challenge['token'],
                $this->securityFlow->getChallengeBinding(),
                $challenge['purpose']
            );
            $this->securityFlow->clear();
            return $this->respond(
                $request,
                false,
                'send_failed',
                'recovery.email-send-failed',
                Router::path('login'),
                200,
                [],
                'login'
            );
        }

        return $this->respond(
            $request,
            true,
            'email_verification_pending',
            'recovery.email-code-sent',
            Router::path('loginRecovery')
        );
    }


    private function startAuthenticatedRecoveryCeremony(
        Request $request,
        string $purpose,
        bool $requiresExistingSet
    ): Response {
        $this->beginRecoveryPresentation($purpose);
        $auth = $this->auth();
        $user = $this->authenticatedUser($auth);
        if ($user === null) {
            return $this->unauthenticated();
        }

        $userId = $user->getId();
        $generation = $this->feature->getAuthenticatorGeneration($userId);
        $activeSetId = $this->feature->getActiveRecoverySetId($userId);
        if ($generation === null
            || ($requiresExistingSet === true && $activeSetId === null)
            || ($requiresExistingSet === false && $activeSetId !== null)
        ) {
            return $this->respond(
                $request,
                false,
                'unavailable',
                'recovery.action-unavailable',
                Router::path('account'),
                200,
                [],
                'account'
            );
        }

        $this->cleanupAbandonedFlow($auth, $userId);
        $challenge = $this->challenges->start(
            $userId,
            $purpose,
            $this->securityFlow->getChallengeBinding(),
            $generation,
            $activeSetId
        );
        $this->securityFlow->setChallenge($challenge['token'], $challenge['purpose']);

        return $this->respond(
            $request,
            true,
            'fresh_reauthentication_pending',
            'recovery.reauthentication-required'
        );
    }


    private function rateLimitedStart(Request $request, RateLimitExceededException $exception, string $redirectName): Response
    {
        $this->securityFlow->clear();
        return $this->respond(
            $request,
            false,
            'rate_limited',
            'recovery.rate-limited',
            Router::path($redirectName),
            429,
            ['retryAfterSeconds' => $exception->retryAfterSeconds],
            $redirectName
        );
    }


    private function authenticatedUser(AuthService $auth): ?User
    {
        $userId = $auth->getAuthenticatedUserId();
        return $userId === null ? null : $this->users->getUserById($userId);
    }


    private function unauthenticated(): Response
    {
        return Response::redirect(Router::path('login'), 303);
    }

}
