<?php

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;
use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitPurpose;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RateLimitExceededException;
use src\Business\RecoveryCodeConsumptionResult;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;
use src\Entity\User;

final class RecoveryCodes extends Controller
{
    private string $flowRedirectName = 'accountRecoveryCodes';
    private string $flowFlashBag = 'recovery';
    private AuthenticationChallengeService $challenges;
    private RecoveryCodeService $recoveryCodes;
    private RecoveryFeatureService $feature;
    private AuthenticatorTOTPService $authenticators;
    private EmailTOTPService $emailTotp;
    private EmailService $email;
    private UserService $users;

    public function __construct(Application $application)
    {
        parent::__construct($application);
        $this->challenges = $this->service('authenticationChallengeService', AuthenticationChallengeService::class);
        $this->recoveryCodes = $this->service('recoveryCodeService', RecoveryCodeService::class);
        $this->feature = $this->service('recoveryFeatureService', RecoveryFeatureService::class);
        $this->authenticators = $this->service('authenticatorTotpService', AuthenticatorTOTPService::class);
        $this->emailTotp = $this->service('emailTotpService', EmailTOTPService::class);
        $this->email = $this->service('emailService', EmailService::class);
        $this->users = $this->service('userService', UserService::class);
    }

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
            $auth->getSecurityChallengeBinding()
        );
        $auth->setSecurityChallenge($challenge['token'], $challenge['purpose']);
        $code = $this->emailTotp->generateEmailTOTPForSession(
            $user->getId(),
            $auth->getSecurityEmailSessionKey()
        );
        if ($this->email->sendEmailTOTP($user->getEmail(), $code) === false) {
            $this->challenges->cancel(
                $challenge['token'],
                $auth->getSecurityChallengeBinding(),
                $challenge['purpose']
            );
            $auth->clearSecurityFlow();
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
        $userId = $auth->getPendingUserId();
        if ($userId === null
            || $auth->isLoginAuthenticatorRequired() === false
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
            $auth->getSecurityChallengeBinding(),
            $generation,
            $activeSetId
        );
        $auth->setSecurityChallenge($challenge['token'], $challenge['purpose']);
        $code = $this->emailTotp->generateEmailTOTPForSession(
            $userId,
            $auth->getSecurityEmailSessionKey()
        );
        if ($this->email->sendEmailTOTP($user->getEmail(), $code) === false) {
            $this->challenges->cancel(
                $challenge['token'],
                $auth->getSecurityChallengeBinding(),
                $challenge['purpose']
            );
            $auth->clearSecurityFlow();
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

    public function account(Request $request): Response
    {
        $user = $this->authenticatedUser($this->auth());
        if ($user === null) {
            return $this->unauthenticated();
        }

        return $this->handleFlow($request, $user, false);
    }

    public function lostAuthenticator(Request $request): Response
    {
        $auth = $this->auth();
        $userId = $auth->getPendingUserId();
        $user = $userId === null ? null : $this->users->getUserById($userId);
        if ($user === null || $auth->isLoginAuthenticatorRequired() === false) {
            return Response::redirect(Router::path('login'), 303);
        }

        return $this->handleFlow($request, $user, true);
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
            $auth->getSecurityChallengeBinding(),
            $generation,
            $activeSetId
        );
        $auth->setSecurityChallenge($challenge['token'], $challenge['purpose']);

        return $this->respond(
            $request,
            true,
            'fresh_reauthentication_pending',
            'recovery.reauthentication-required'
        );
    }

    private function handleFlow(Request $request, User $user, bool $lostFlow): Response
    {
        $this->flowRedirectName = $lostFlow === true ? 'loginRecovery' : 'accountRecoveryCodes';
        $this->application->get('translationService')->setFile('recovery');
        $auth = $this->auth();
        $token = $auth->getSecurityChallengeToken();
        $purpose = $auth->getSecurityChallengePurpose();
        $expectedPurpose = $lostFlow === true
            ? AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY
            : $purpose;
        if ($token === null || $purpose === null || $purpose !== $expectedPurpose) {
            return $this->flowUnavailable($request, $lostFlow);
        }
        $this->flowFlashBag = $this->flashBagForPurpose($purpose);
        $this->consumeFlash('recovery');

        $challenge = $this->challenges->getActive(
            $token,
            $auth->getSecurityChallengeBinding(),
            $purpose
        );
        if ($challenge === null || (int) $challenge->user_id !== $user->getId()) {
            $this->cleanupAbandonedFlow($auth, $user->getId());
            return $this->flowUnavailable($request, $lostFlow, 'recovery.challenge-expired');
        }
        if ($this->pendingAuthenticatorSecretIsRequired($challenge) === true
            && $auth->getPendingAuthenticatorSecret() === null
        ) {
            $this->cleanupAbandonedFlow($auth, $user->getId());
            return $this->flowUnavailable(
                $request,
                $lostFlow,
                'recovery.pending-authenticator-secret-lost'
            );
        }

        if (strtoupper($request->getMethod()) === 'POST') {
            return $this->handleAction($request, $user, $challenge, $lostFlow);
        }

        return $this->renderFlow($user, $challenge, $lostFlow);
    }

    private function renderFlow(
        User $user,
        object $challenge,
        bool $lostFlow,
        ?array $codes = null
    ): Response {
        $auth = $this->auth();
        $pendingSecret = $auth->getPendingAuthenticatorSecret();
        $showAuthenticatorConfiguration = in_array((string) $challenge->state, [
            'authenticator_configuration_presented',
            'new_authenticator_configuration_presented',
        ], true);
        $label = APP_NAME . ':' . $user->getEmail();

        return Response::html(
            $this->twig->render('recovery-flow.twig', array_merge(
                $this->twigVariables,
                [
                'flow' => [
                    'purpose' => (string) $challenge->purpose,
                    'state' => (string) $challenge->state,
                    'lost' => $lostFlow,
                    'codes' => $codes,
                    'pendingSetId' => $auth->getPendingRecoverySetId(),
                    'remainingCount' => $this->feature->getUnusedRecoveryCodeCount($user->getId()),
                    'authenticatorSecret' => $showAuthenticatorConfiguration === true ? $pendingSecret : null,
                    'otpauth' => $showAuthenticatorConfiguration === true && $pendingSecret !== null
                        ? $this->authenticators->generateProvisioningUri($pendingSecret, $label)
                        : null,
                    'qrCodeUrl' => $showAuthenticatorConfiguration === true && $pendingSecret !== null
                        ? $this->authenticators->generateQRCode($pendingSecret, $label)
                        : null,
                    'digits' => (int) AUTHENTICATOR_TOTP_DIGITS,
                    'emailDigits' => (int) TOTP_DIGITS,
                ],
                'user' => $user,
                'recovery' => $this->consumeFlash(
                    $this->flashBagForPurpose((string) $challenge->purpose)
                ),
                ]
            )),
            200,
            $this->noStoreHeaders()
        );
    }

    private function handleAction(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        $action = (string) $request->post('action', '');

        try {
            return match ($action) {
                'verify_email' => $this->verifyEmail($request, $user, $challenge, $lostFlow),
                'verify_authenticator' => $this->verifyAuthenticator($request, $user, $challenge, $lostFlow),
                'verify_recovery_code' => $this->verifyLostRecoveryCode($request, $user, $challenge),
                'confirm_replacement' => $this->confirmReplacement($request, $user, $challenge, $lostFlow),
                'acknowledge' => $this->acknowledge($request, $user, $challenge, $lostFlow),
                'codes_unavailable' => $this->replaceUnavailableDisplay($request, $user, $challenge, $lostFlow),
                'cancel' => $this->cancel($request, $user, $challenge, $lostFlow),
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

    private function verifyEmail(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        if ((string) $challenge->state !== 'email_verification_pending'
            || $this->emailTotp->verifyEmailTOTPForSession(
                $user->getId(),
                $this->normalizeOtp((string) $request->post('email_code', '')),
                $this->auth()->getSecurityEmailSessionKey()
            ) === false
        ) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.email-code-invalid');
        }

        $purpose = (string) $challenge->purpose;
        $token = $this->auth()->getSecurityChallengeToken() ?? '';
        $binding = $this->auth()->getSecurityChallengeBinding();
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
        $this->auth()->setPendingAuthenticatorSecret($secret);
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

    private function verifyAuthenticator(
        Request $request,
        User $user,
        object $challenge,
        bool $lostFlow
    ): Response {
        $state = (string) $challenge->state;
        $code = $this->normalizeOtp((string) $request->post('authenticator_code', ''));
        $token = $this->auth()->getSecurityChallengeToken() ?? '';
        $binding = $this->auth()->getSecurityChallengeBinding();
        $purpose = (string) $challenge->purpose;

        if ($state === 'fresh_reauthentication_pending') {
            if ($this->authenticators->verifyCode($user->getId(), $code) === false) {
                return $this->respond($request, false, $state, 'recovery.authenticator-code-invalid');
            }

            $fresh = $this->challenges->transition(
                $token,
                $binding,
                $purpose,
                'fresh_reauthentication_pending',
                'freshly_reauthenticated'
            );
            if ($fresh === null) {
                return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
            }

            if ($purpose === AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES) {
                return $this->generateAndPresent($request, $user, $fresh, 'freshly_reauthenticated', false);
            }

            $warning = $this->challenges->transition(
                $token,
                $binding,
                $purpose,
                'freshly_reauthenticated',
                'replacement_warning_presented'
            );
            return $this->respond(
                $request,
                $warning !== null,
                'replacement_warning_presented',
                $warning !== null ? 'recovery.replacement-warning' : 'recovery.challenge-expired'
            );
        }

        $expectedState = $lostFlow === true
            ? 'new_authenticator_configuration_presented'
            : 'authenticator_configuration_presented';
        $pendingSecret = $this->auth()->getPendingAuthenticatorSecret();
        if ($state !== $expectedState
            || $pendingSecret === null
            || $this->authenticators->verifySecret($pendingSecret, $code) === false
        ) {
            return $this->respond($request, false, $state, 'recovery.authenticator-code-invalid');
        }

        $verifiedState = $lostFlow === true ? 'new_authenticator_verified' : 'authenticator_verified';
        $verified = $this->challenges->transition(
            $token,
            $binding,
            $purpose,
            $expectedState,
            $verifiedState
        );
        if ($verified === null) {
            return $this->respond($request, false, 'expired', 'recovery.challenge-expired');
        }

        return $this->generateAndPresent($request, $user, $verified, $verifiedState, $lostFlow);
    }

    private function verifyLostRecoveryCode(Request $request, User $user, object $challenge): Response
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

        $token = $this->auth()->getSecurityChallengeToken() ?? '';
        $binding = $this->auth()->getSecurityChallengeBinding();
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

    private function confirmReplacement(
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

        $token = $this->auth()->getSecurityChallengeToken() ?? '';
        $binding = $this->auth()->getSecurityChallengeBinding();
        $purpose = (string) $challenge->purpose;
        if ((bool) $lostFlow === true) {
            $secret = $this->authenticators->generateSecret();
            $this->auth()->setPendingAuthenticatorSecret($secret);
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

        return $this->generateAndPresent($request, $user, $confirmed, 'replacement_confirmed', false);
    }

    private function generateAndPresent(
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
        $this->auth()->setPendingRecoverySetId($result->setId);

        $presentedState = match ($purpose) {
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES =>
                'replacement_codes_presented_unacknowledged',
            AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY =>
                'new_recovery_codes_presented_unacknowledged',
            default => 'recovery_codes_presented_unacknowledged',
        };
        $presented = $this->challenges->transition(
            $this->auth()->getSecurityChallengeToken() ?? '',
            $this->auth()->getSecurityChallengeBinding(),
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

        $this->auth()->markRecoveryCodesDelivered($result->setId);
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
            array_merge([
                'remainingCount' => count($result->codes),
                'codes' => $result->codes,
            ], $this->oneTimeDisplayLabels())
        );
    }

    private function acknowledge(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        if ($request->post('saved_codes') !== '1') {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.acknowledgement-required');
        }

        $auth = $this->auth();
        $setId = $auth->getPendingRecoverySetId();
        $token = $auth->getSecurityChallengeToken() ?? '';
        $binding = $auth->getSecurityChallengeBinding();
        $purpose = (string) $challenge->purpose;
        if ($setId === null || $auth->getDeliveredRecoverySetId() !== $setId) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.codes-display-unavailable');
        }

        $completed = false;
        if ($purpose === AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT) {
            $secret = $auth->getPendingAuthenticatorSecret();
            $completed = $secret !== null && $this->feature->completeEnrollment(
                $user->getId(),
                $setId,
                $secret,
                $token,
                $binding
            );
        } elseif ((bool) $lostFlow === true) {
            $secret = $auth->getPendingAuthenticatorSecret();
            $activeSetId = (int) ($challenge->baseline_recovery_code_set_id ?? 0);
            $generation = (int) ($challenge->baseline_authenticator_generation ?? 0);
            $version = $secret === null ? null : $this->feature->completeLostAuthenticatorReplacement(
                $user->getId(),
                $setId,
                $activeSetId,
                $generation,
                $secret,
                $token,
                $binding
            );
            $completed = $version !== null;
            if ($completed === true) {
                $auth->loginUser($user->getId());
            }
        } else {
            if ($this->challenges->isFreshReauthentication($challenge, $purpose) === false) {
                return $this->respond($request, false, 'expired', 'recovery.reauthentication-expired');
            }
            $expectedActiveSetId = $challenge->baseline_recovery_code_set_id === null
                ? null
                : (int) $challenge->baseline_recovery_code_set_id;
            $completed = $this->feature->completeRecoverySetActivation(
                $user->getId(),
                $setId,
                $expectedActiveSetId,
                $token,
                $binding,
                $purpose
            );
        }

        if ($completed === false) {
            return $this->respond($request, false, (string) $challenge->state, 'recovery.activation-failed');
        }

        $auth->clearSecurityFlow();
        $auth->rotateCsrfToken();
        if (in_array($purpose, [
            AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES,
            AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY,
        ], true)) {
            $this->email->sendSecurityNotification(
                $user->getEmail(),
                $lostFlow === true ? 'Authenticator replaced' : 'Recovery codes replaced',
                $lostFlow === true
                    ? 'Your authenticator and recovery-code set were replaced. Other browser sessions were revoked.'
                    : 'A new recovery-code set is active. All previous recovery codes are now invalid.'
            );
        }

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

    private function replaceUnavailableDisplay(
        Request $request,
        User $user,
        object $challenge,
        bool $lostFlow
    ): Response {
        $pendingSetId = $this->auth()->getPendingRecoverySetId();
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
        $this->auth()->setPendingRecoverySetId(null);

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
        $this->auth()->setPendingRecoverySetId($result->setId);
        $this->auth()->markRecoveryCodesDelivered($result->setId);

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
            array_merge([
                'remainingCount' => count($result->codes),
                'codes' => $result->codes,
            ], $this->oneTimeDisplayLabels())
        );
    }

    private function cancel(Request $request, User $user, object $challenge, bool $lostFlow): Response
    {
        $auth = $this->auth();
        $setId = $auth->getPendingRecoverySetId();
        if ($setId !== null) {
            $this->recoveryCodes->invalidatePendingSet($user->getId(), $setId, 'flow_cancelled');
        }
        $this->challenges->cancel(
            $auth->getSecurityChallengeToken() ?? '',
            $auth->getSecurityChallengeBinding(),
            (string) $challenge->purpose
        );
        $auth->clearSecurityFlow();

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

    private function cleanupAbandonedFlow(AuthService $auth, int $userId): void
    {
        $token = $auth->getSecurityChallengeToken();
        $purpose = $auth->getSecurityChallengePurpose();
        $setId = $auth->getPendingRecoverySetId();
        if ($setId !== null) {
            $this->recoveryCodes->invalidatePendingSet($userId, $setId, 'challenge_expired');
        }
        if ($token !== null && $purpose !== null) {
            $this->challenges->cancel(
                $token,
                $auth->getSecurityChallengeBinding(),
                $purpose
            );
        }
        $auth->clearSecurityFlow();
    }

    private function flowUnavailable(
        Request $request,
        bool $lostFlow,
        string $message = 'recovery.no-active-flow'
    ): Response {
        return $this->respond(
            $request,
            false,
            'unavailable',
            $message,
            $lostFlow === true ? Router::path('login') : Router::path('account'),
            409,
            [],
            $lostFlow === true ? 'login' : 'account'
        );
    }

    private function respond(
        Request $request,
        bool $success,
        string $state,
        string $messageKey,
        ?string $redirect = null,
        int $status = 200,
        array $extra = [],
        ?string $flashBag = null
    ): Response {
        $redirect ??= Router::path($this->flowRedirectName);
        $payload = array_merge([
            'success' => $success,
            'state' => $state,
            'nextStep' => $state,
            'message' => __($messageKey),
            'errors' => $success === true ? [] : [__($messageKey)],
            'redirect' => $redirect,
        ], $extra);

        if ($this->expectsJson($request) === true) {
            return Response::json(
                $payload,
                $success === true ? $status : max($status, 422),
                $this->noStoreHeaders()
            );
        }

        $this->flash(
            $flashBag ?? $this->flowFlashBag,
            $success === true ? 'success' : 'errors',
            __($messageKey)
        );
        return Response::redirect($redirect, 303);
    }

    private function beginRecoveryPresentation(string $purpose): void
    {
        $this->flowFlashBag = $this->flashBagForPurpose($purpose);
        foreach ([
            'recovery',
            $this->flashBagForPurpose(AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT),
            $this->flashBagForPurpose(AuthenticationChallengeService::PURPOSE_LOST_AUTHENTICATOR_RECOVERY),
            $this->flashBagForPurpose(AuthenticationChallengeService::PURPOSE_INITIAL_RECOVERY_CODES),
            $this->flashBagForPurpose(AuthenticationChallengeService::PURPOSE_REPLACE_RECOVERY_CODES),
        ] as $flashBag) {
            $this->consumeFlash($flashBag);
        }
    }

    private function flashBagForPurpose(string $purpose): string
    {
        return 'recovery.' . $purpose;
    }

    private function rateLimitContext(int $userId, object $challenge): AuthenticationRateLimitContext
    {
        $session = $this->application->get('sessionService');
        return AuthenticationRateLimitContext::forUser(
            $userId,
            (string) $session->get('_IPaddress', '127.0.0.1'),
            $this->auth()->getSecurityChallengeBinding(),
            (string) $challenge->id
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

    private function normalizeOtp(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }

    private function oneTimeDisplayLabels(): array
    {
        return [
            'displayWarning' => __('recovery.save-codes-warning'),
            'codesListLabel' => __('recovery.codes-list-label'),
            'acknowledgementLabel' => __('recovery.saved-codes-acknowledgement'),
            'activateLabel' => __('recovery.activate'),
            'unavailableLabel' => __('recovery.codes-unavailable-action'),
            'cancelLabel' => __('recovery.cancel'),
        ];
    }

    private function pendingAuthenticatorSecretIsRequired(object $challenge): bool
    {
        $state = (string) $challenge->state;
        if (in_array($state, [
            'authenticator_configuration_presented',
            'authenticator_verified',
            'new_authenticator_configuration_presented',
            'new_authenticator_verified',
            'new_recovery_codes_presented_unacknowledged',
        ], true) === true) {
            return true;
        }

        return (string) $challenge->purpose === AuthenticationChallengeService::PURPOSE_AUTHENTICATOR_ENROLLMENT
            && $state === 'recovery_codes_presented_unacknowledged';
    }

    private function expectsJson(Request $request): bool
    {
        return str_contains(strtolower((string) $request->getHeader('Accept')), 'application/json')
            || strtolower((string) $request->getHeader('X-Requested-With')) === 'xmlhttprequest';
    }

    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control: no-store, private',
            'Pragma: no-cache',
            'Referrer-Policy: no-referrer',
        ];
    }

    /** @template T of object @param class-string<T> $class */
    private function service(string $name, string $class): object
    {
        $service = $this->application->get($name);
        if (($service instanceof $class) === false) {
            throw new \RuntimeException($name . ' service is not available.');
        }

        return $service;
    }
}
