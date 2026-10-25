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

abstract class RecoveryControllerComponent extends Controller
{
    protected const NO_STORE_HEADERS = [
        'Cache-Control: no-store, private',
        'Pragma: no-cache',
        'Referrer-Policy: no-referrer',
    ];
    protected string $flowRedirectName = 'accountRecoveryCodes';
    protected string $flowFlashBag = 'recovery';
    protected AuthenticationChallengeService $challenges;
    protected RecoveryCodeService $recoveryCodes;
    protected RecoveryFeatureService $feature;
    protected RecoveryFlowService $flow;
    protected AuthenticatorTOTPService $authenticators;
    protected EmailTOTPService $emailTotp;
    protected EmailService $email;
    protected UserService $users;
    protected PendingAuthenticationSession $pendingAuthentication;
    protected SecurityFlowSession $securityFlow;

    public function __construct(
        Application $application,
        string $flowRedirectName = 'accountRecoveryCodes',
        string $flowFlashBag = 'recovery'
    )
    {
        parent::__construct($application);
        $this->flowRedirectName = $flowRedirectName;
        $this->flowFlashBag = $flowFlashBag;
        $this->challenges = $application->get(AuthenticationChallengeService::class);
        $this->recoveryCodes = $application->get(RecoveryCodeService::class);
        $this->feature = $application->get(RecoveryFeatureService::class);
        $this->flow = $application->get(RecoveryFlowService::class);
        $this->authenticators = $application->get(AuthenticatorTOTPService::class);
        $this->emailTotp = $application->get(EmailTOTPService::class);
        $this->email = $application->get(EmailService::class);
        $this->users = $application->get(UserService::class);
        $session = $application->get(\app\Service\SessionService::class);
        $pending = $application->getOptional(PendingAuthenticationSession::class);
        $security = $application->getOptional(SecurityFlowSession::class);
        $this->pendingAuthentication = $pending instanceof PendingAuthenticationSession
            ? $pending : new PendingAuthenticationSession($session);
        $this->securityFlow = $security instanceof SecurityFlowSession
            ? $security : new SecurityFlowSession($session);
    }

    protected function renderFlow(
        User $user,
        object $challenge,
        bool $lostFlow,
        ?array $codes = null
    ): Response {
        $auth = $this->auth();
        $pendingSecret = $this->securityFlow->getPendingAuthenticatorSecret();
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
                    'pendingSetId' => $this->securityFlow->getPendingRecoverySetId(),
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
            self::NO_STORE_HEADERS
        );
    }


    protected function cleanupAbandonedFlow(AuthService $auth, int $userId): void
    {
        $token = $this->securityFlow->getChallengeToken();
        $purpose = $this->securityFlow->getChallengePurpose();
        $setId = $this->securityFlow->getPendingRecoverySetId();
        if ($setId !== null) {
            $this->recoveryCodes->invalidatePendingSet($userId, $setId, 'challenge_expired');
        }
        if ($token !== null && $purpose !== null) {
            $this->challenges->cancel(
                $token,
                $this->securityFlow->getChallengeBinding(),
                $purpose
            );
        }
        $this->securityFlow->clear();
    }


    protected function flowUnavailable(
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


    protected function respond(
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
                self::NO_STORE_HEADERS
            );
        }

        $this->flash(
            $flashBag ?? $this->flowFlashBag,
            $success === true ? 'success' : 'errors',
            __($messageKey)
        );
        return Response::redirect($redirect, 303);
    }


    protected function beginRecoveryPresentation(string $purpose): void
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


    protected function flashBagForPurpose(string $purpose): string
    {
        return 'recovery.' . $purpose;
    }


    protected function rateLimitContext(int $userId, object $challenge): AuthenticationRateLimitContext
    {
        $session = $this->application->get(\app\Service\SessionService::class);
        return AuthenticationRateLimitContext::forUser(
            $userId,
            (string) $session->get('_IPaddress', '127.0.0.1'),
            $this->securityFlow->getChallengeBinding(),
            (string) $challenge->id
        );
    }


    protected function rateLimitContextForToken(int $userId, string $token): AuthenticationRateLimitContext
    {
        $session = $this->application->get(\app\Service\SessionService::class);
        return AuthenticationRateLimitContext::forUser(
            $userId,
            (string) $session->get('_IPaddress', '127.0.0.1'),
            $this->securityFlow->getChallengeBinding(),
            $token
        );
    }


    protected function expectsJson(Request $request): bool
    {
        return str_contains(strtolower((string) $request->getHeader('Accept')), 'application/json')
            || strtolower((string) $request->getHeader('X-Requested-With')) === 'xmlhttprequest';
    }


}
