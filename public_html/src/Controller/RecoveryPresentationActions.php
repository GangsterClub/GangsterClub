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

final class RecoveryPresentationActions extends RecoveryControllerComponent
{
    public function generateAndPresent(
        Request $request,
        User $user,
        object $challenge,
        string $expectedState,
        bool $lostFlow
    ): Response {
        $purpose = (string) $challenge->purpose;
        if (in_array($purpose, [
            AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES,
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES,
        ], true) === true && $this->challenges->isFreshReauthentication($challenge, $purpose) === false) {
            return $this->respond($request, false, 'expired', 'recovery.reauthentication-expired');
        }

        $generation = (int) ($challenge->baseline_authenticator_generation ?? 1);
        if ((bool) $lostFlow === true) {
            ++$generation;
        }
        $replacesSetId = $challenge->baseline_recovery_code_set_id === null
            ? null
            : (int) $challenge->baseline_recovery_code_set_id;
        $ratePurpose = AuthenticationRateLimitPurpose::from($purpose);
        $result = $this->recoveryCodes->generatePendingSet(
            $user->getId(),
            $ratePurpose,
            $this->rateLimitContext($user->getId(), $challenge),
            $generation,
            (int) $challenge->id,
            $replacesSetId
        );
        $this->securityFlow->setPendingRecoverySetId($result->setId);

        $presentedState = match ($purpose) {
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES =>
                'replacement_codes_presented_unacknowledged',
            AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY =>
                'new_recovery_codes_presented_unacknowledged',
            default => 'recovery_codes_presented_unacknowledged',
        };
        $presented = $this->challenges->transition(
            $this->securityFlow->getChallengeToken() ?? '',
            $this->securityFlow->getChallengeBinding(),
            $purpose,
            $expectedState,
            $presentedState
        );
        if ($presented === null) {
            $this->recoveryCodes->invalidatePendingSet(
                $user->getId(),
                $result->setId,
                'challenge_transition_failed'
            );
            return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
        }

        $this->securityFlow->markRecoveryCodesDelivered($result->setId);
        if ($this->expectsJson($request) === false) {
            return $this->renderFlow($user, $presented, $lostFlow, $result->codes);
        }

        return $this->respond(
            $request,
            true,
            $presentedState,
            'recovery.codes-generated',
            null,
            200,
            [
                'remainingCount' => count($result->codes),
                'codes' => $result->codes,
            ]
        );
    }


    public function acknowledge(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        if ($request->post('saved_codes') !== '1') {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.acknowledgement-required');
        }

        $auth = $this->auth();
        $setId = $this->securityFlow->getPendingRecoverySetId();
        $token = $this->securityFlow->getChallengeToken() ?? '';
        $binding = $this->securityFlow->getChallengeBinding();
        $purpose = (string) $challenge->purpose;
        if ($setId === null || $this->securityFlow->getDeliveredRecoverySetId() !== $setId) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.codes-display-unavailable');
        }

        $completionStatus = $this->feature->completeAcknowledgedFlow(
            $user->getId(),
            $setId,
            $challenge,
            $this->securityFlow->getPendingAuthenticatorSecret(),
            $token,
            $binding,
            $lostFlow
        );
        if ($completionStatus === RecoveryFeatureService::COMPLETION_REAUTHENTICATION_EXPIRED) {
            return $this->respond($request, false, 'expired', 'recovery.reauthentication-expired');
        }
        if ($completionStatus === RecoveryFeatureService::COMPLETION_FAILED) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.activation-failed');
        }
        if ($lostFlow === true) {
            $auth->loginUser($user->getId());
        }

        $this->securityFlow->clear();
        $auth->rotateCsrfToken();
        $this->email->sendRecoveryCompletionNotification($user->getEmail(), $purpose, $lostFlow);

        return $this->respond(
            $request,
            true,
            'completed',
            $purpose === AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES
                ? 'recovery.previous-codes-invalid'
                : 'recovery.completed',
            Router::path('account'),
            200,
            [],
            'account'
        );
    }


    public function replaceUnavailableDisplay(
        Request $request,
        User $user,
        object $challenge,
        bool $lostFlow
    ): Response {
        $pendingSetId = $this->securityFlow->getPendingRecoverySetId();
        $presentedStates = [
            'recovery_codes_presented_unacknowledged',
            'replacement_codes_presented_unacknowledged',
            'new_recovery_codes_presented_unacknowledged',
        ];
        if ($pendingSetId === null || in_array((string) $challenge->state, $presentedStates, true) === false) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.invalid-action');
        }

        $this->recoveryCodes->invalidatePendingSet(
            $user->getId(),
            $pendingSetId,
            'unacknowledged_display'
        );
        $this->securityFlow->setPendingRecoverySetId(null);

        $generation = (int) ($challenge->baseline_authenticator_generation ?? 1) + ($lostFlow === true ? 1 : 0);
        $replacesSetId = $challenge->baseline_recovery_code_set_id === null
            ? null
            : (int) $challenge->baseline_recovery_code_set_id;
        $result = $this->recoveryCodes->generatePendingSet(
            $user->getId(),
            AuthenticationRateLimitPurpose::from((string) $challenge->purpose),
            $this->rateLimitContext($user->getId(), $challenge),
            $generation,
            (int) $challenge->id,
            $replacesSetId
        );
        $this->securityFlow->setPendingRecoverySetId($result->setId);
        $this->securityFlow->markRecoveryCodesDelivered($result->setId);

        if ($this->expectsJson($request) === false) {
            return $this->renderFlow($user, $challenge, $lostFlow, $result->codes);
        }

        return $this->respond(
            $request,
            true,
            (string) $challenge->state,
            'recovery.codes-regenerated',
            null,
            200,
            [
                'remainingCount' => count($result->codes),
                'codes' => $result->codes,
            ]
        );
    }


    public function cancel(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        $auth = $this->auth();
        $setId = $this->securityFlow->getPendingRecoverySetId();
        if ($setId !== null) {
            $this->recoveryCodes->invalidatePendingSet($user->getId(), $setId, 'flow_cancelled');
        }
        $this->challenges->cancel(
            $this->securityFlow->getChallengeToken() ?? '',
            $this->securityFlow->getChallengeBinding(),
            (string) $challenge->purpose
        );
        $this->securityFlow->clear();

        return $this->respond(
            $request,
            true,
            'cancelled',
            'recovery.cancelled',
            $lostFlow === true ? Router::path('login') : Router::path('account'),
            200,
            [],
            $lostFlow === true ? 'login' : 'account'
        );
    }

}
