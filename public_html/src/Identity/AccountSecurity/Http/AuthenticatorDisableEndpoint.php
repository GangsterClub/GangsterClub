<?php

declare(strict_types=1);

namespace src\Identity\AccountSecurity\Http;

use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\FlashBag;
use app\Service\SessionService;
use app\Service\TranslationService;
use src\Identity\AccountSecurity\Application\AuthenticatorDisableOutcome;
use src\Identity\AccountSecurity\Application\DisableAuthenticator;
use Twig\Environment;

final class AuthenticatorDisableEndpoint
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DisableAuthenticator $disableAuthenticator,
        private readonly FlashBag $flash,
        private readonly SessionService $session,
        private readonly TranslationService $translation,
        private readonly Environment $twig
    ) {
    }

    public function start(Request $request): Response
    {
        if ($this->auth->getAuthenticatedUserId() === null) {
            return Response::redirect(Router::path('login'), 303);
        }
        return Response::redirect(Router::path('accountAuthenticatorDisable'), 303);
    }

    public function disable(Request $request): Response
    {
        $this->rememberPreviousRoute();
        $this->translation->setFile('account');
        $userId = $this->auth->getAuthenticatedUserId();
        if ($userId === null) {
            return Response::redirect(Router::path('login'), 303);
        }
        if ($request->getMethod() === 'POST') {
            return $this->mapOutcome(
                $request,
                $this->disableAuthenticator->disable(
                    $userId,
                    (string) $request->post('authenticator_disable_code', '')
                )
            );
        }
        return Response::html($this->twig->render('authenticator-disable.twig', [
            'account' => $this->flash->consume('authenticator.disable'),
            'cancelDisable' => [
                'action' => $this->translation->get('account.authenticator-cancel'),
                'backUrl' => Router::path('account'),
            ],
            'authenticator' => ['digits' => AUTHENTICATOR_TOTP_DIGITS],
        ]));
    }

    private function mapOutcome(Request $request, AuthenticatorDisableOutcome $outcome): Response
    {
        $messageKeys = [
            AuthenticatorDisableOutcome::CODE_REQUIRED->value => 'account.authenticator-disable-code-required',
            AuthenticatorDisableOutcome::INVALID_CODE->value => 'account.authenticator-disable-invalid-code',
            AuthenticatorDisableOutcome::ERROR->value => 'account.authenticator-disable-error',
            AuthenticatorDisableOutcome::DISABLED->value => 'account.authenticator-disabled',
        ];
        $message = $this->translation->get($messageKeys[$outcome->value]);
        $success = $outcome === AuthenticatorDisableOutcome::DISABLED;
        if ($this->expectsStructuredResponse($request) === true) {
            return Response::json([
                'success' => $success,
                'state' => $success === true ? 'authenticator_disabled' : 'authenticator_verification_pending',
                'nextStep' => $success === true ? 'account_security' : 'verify_authenticator',
                'message' => $message,
                'errors' => $success === true ? [] : [$message],
                'redirect' => Router::path('account'),
            ], $success === true ? 200 : 422, $this->securityHeaders());
        }
        if ($success === true) {
            $this->flash->add('account', 'success', $message);
            return Response::redirect(Router::path('account'), 303);
        }
        $this->flash->add('authenticator.disable', 'errors', $message);
        return Response::redirect(Router::path('accountAuthenticatorDisable'), 303);
    }

    private function expectsStructuredResponse(Request $request): bool
    {
        return str_contains(strtolower((string) $request->getHeader('Accept')), 'application/json')
            || strtolower((string) $request->getHeader('X-Requested-With')) === 'xmlhttprequest';
    }

    private function securityHeaders(): array
    {
        return ['Cache-Control: no-store, private', 'Pragma: no-cache', 'Referrer-Policy: no-referrer'];
    }

    private function rememberPreviousRoute(): void
    {
        if (defined('REQUEST_METHOD') === true && REQUEST_METHOD === 'GET') {
            $this->session->set('PREV_ROUTE', REQUEST_URI);
        }
    }
}
