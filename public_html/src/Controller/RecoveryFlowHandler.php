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

final class RecoveryFlowHandler extends RecoveryControllerComponent
{
    public function account(Request $request): Response
    {
        $userId = $this->auth()->getAuthenticatedUserId();
        $user = $userId === null ? null : $this->users->getUserById($userId);
        if ($user === null) {
            return Response::redirect(Router::path('login'), 303);
        }

        return $this->handleFlow($request, $user, false);
    }


    public function lostAuthenticator(Request $request): Response
    {
        $auth = $this->auth();
        $userId = $this->pendingAuthentication->getUserId();
        $user = $userId === null ? null : $this->users->getUserById($userId);
        if ($user === null || $this->pendingAuthentication->isAuthenticatorRequired() === false) {
            return Response::redirect(Router::path('login'), 303);
        }

        return $this->handleFlow($request, $user, true);
    }


    private function handleFlow(Request $request, User $user, bool $lostFlow): Response
    {
        $this->flowRedirectName = $lostFlow === true ? 'loginRecovery' : 'accountRecoveryCodes';
        $this->application->get(\app\Service\TranslationService::class)->setFile('recovery');
        $auth = $this->auth();
        $token = $this->securityFlow->getChallengeToken();
        $purpose = $this->securityFlow->getChallengePurpose();
        $expectedPurpose = $lostFlow === true
            ? AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY
            : $purpose;
        $flowChallenge = $this->flow->resolveChallenge(
            $user->getId(),
            $token,
            $purpose,
            $expectedPurpose,
            $this->securityFlow->getChallengeBinding(),
            $this->securityFlow->getPendingAuthenticatorSecret()
        );
        if ($flowChallenge->status === RecoveryFlowChallengeResult::UNAVAILABLE) {
            return $this->flowUnavailable($request, $lostFlow);
        }
        $this->flowFlashBag = $this->flashBagForPurpose((string) $purpose);
        $this->consumeFlash('recovery');

        if ($flowChallenge->status === RecoveryFlowChallengeResult::EXPIRED) {
            $this->cleanupAbandonedFlow($auth, $user->getId());
            return $this->flowUnavailable($request, $lostFlow, 'recovery.challenge-expired');
        }
        if ($flowChallenge->status === RecoveryFlowChallengeResult::PENDING_SECRET_MISSING) {
            $this->cleanupAbandonedFlow($auth, $user->getId());
            return $this->flowUnavailable(
                $request,
                $lostFlow,
                'recovery.pending-authenticator-secret-lost'
            );
        }
        $challenge = $flowChallenge->challenge;
        if ($challenge === null) {
            return $this->flowUnavailable($request, $lostFlow);
        }

        if (strtoupper($request->getMethod()) === 'POST') {
            return $this->handleAction($request, $user, $challenge, $lostFlow);
        }

        return $this->renderFlow($user, $challenge, $lostFlow);
    }


    private function handleAction(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        $action = (string) $request->post('action', '');
        $verification = new RecoveryVerificationActions(
            $this->application,
            $this->flowRedirectName,
            $this->flowFlashBag
        );
        $presentation = new RecoveryPresentationActions(
            $this->application,
            $this->flowRedirectName,
            $this->flowFlashBag
        );

        try {
            return match ($action) {
                'verify_email' => $verification->verifyEmail($request, $user, $challenge, $lostFlow),
                'verify_authenticator' => $verification->verifyAuthenticator($request, $user, $challenge, $lostFlow),
                'verify_recovery_code' => $verification->verifyLostRecoveryCode($request, $user, $challenge),
                'confirm_replacement' => $verification->confirmReplacement($request, $user, $challenge, $lostFlow),
                'acknowledge' => $presentation->acknowledge($request, $user, $challenge, $lostFlow),
                'codes_unavailable' => $presentation->replaceUnavailableDisplay($request, $user, $challenge, $lostFlow),
                'cancel' => $presentation->cancel($request, $user, $challenge, $lostFlow),
                default => $this->respond($request, false, (string) $challenge->state, 'recovery.invalid-action'),
            };
        } catch (RateLimitExceededException $exception) {
            return $this->respond(
                $request,
                false,
                (string) $challenge->state,
                'recovery.rate-limited',
                null,
                429,
                ['retryAfterSeconds' => $exception->retryAfterSeconds]
            );
        }
    }

}
