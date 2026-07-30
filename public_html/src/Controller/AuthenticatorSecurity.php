<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;

class AuthenticatorSecurity extends Controller
{
    private AuthenticatorTOTPService $authenticatorService;

    private RecoveryFeatureService $recoveryFeatureService;

    public function __construct(Application $application)
    {
        parent::__construct($application);

        $authenticatorService = $application->get('authenticatorTotpService');
        if (($authenticatorService instanceof AuthenticatorTOTPService) === false) {
            throw new \RuntimeException('authenticatorTotpService service is not available.');
        }

        $this->authenticatorService = $authenticatorService;

        $recoveryFeatureService = $application->get('recoveryFeatureService');
        if (($recoveryFeatureService instanceof RecoveryFeatureService) === false) {
            throw new \RuntimeException('recoveryFeatureService service is not available.');
        }

        $this->recoveryFeatureService = $recoveryFeatureService;
    }

    public function startDisable(Request $request): Response
    {
        $auth = $this->auth();

        if ($auth->getAuthenticatedUserId() === null) {
            return Response::redirect(APP_BASE . '/login', 303);
        }

        return Response::redirect(APP_BASE . '/account/authenticator/disable', 303);
    }

    public function disable(Request $request): Response
    {
        $this->application->get('translationService')->setFile('account');

        $auth = $this->auth();
        $userId = $auth->getAuthenticatedUserId();

        if ($userId === null) {
            return Response::redirect(APP_BASE . '/login', 303);
        }

        if ($request->getMethod() === 'POST') {
            $code = preg_replace(
                '/\s+/',
                '',
                (string) $request->post('authenticator_disable_code', '')
            ) ?? '';

            if (preg_match('/^\d{' . AUTHENTICATOR_TOTP_DIGITS . '}$/', $code) !== 1) {
                return $this->disableFailure(
                    $request,
                    __('account.authenticator-disable-code-required')
                );
            }

            if ($this->authenticatorService->verifyEnabledSecret($userId, $code) === false) {
                return $this->disableFailure(
                    $request,
                    __('account.authenticator-disable-invalid-code')
                );
            }

            if ($this->recoveryFeatureService->disableAuthenticatorAndRecoveryCodes($userId) === false) {
                return $this->disableFailure(
                    $request,
                    __('account.authenticator-disable-error')
                );
            }

            $auth->setPendingAuthenticatorSecret(null);
            $auth->rotateCsrfToken();

            if ($this->expectsStructuredResponse($request)) {
                return Response::json(
                    [
                        'success' => true,
                        'state' => 'authenticator_disabled',
                        'nextStep' => 'account_security',
                        'message' => __('account.authenticator-disabled'),
                        'errors' => [],
                        'redirect' => APP_BASE . '/account',
                    ],
                    200,
                    $this->securityHeaders()
                );
            }

            $this->flash(
                'account',
                'success',
                __('account.authenticator-disabled')
            );

            return Response::redirect(APP_BASE . '/account', 303);
        }

        $messages = $this->consumeFlash('authenticator.disable');

        return Response::html(
            $this->twig->render(
                'authenticator-disable.twig',
                array_merge($this->twigVariables, [
                    'account' => $messages,
                ])
            )
        );
    }

    private function disableFailure(Request $request, string $message): Response
    {
        if ($this->expectsStructuredResponse($request)) {
            return Response::json(
                [
                    'success' => false,
                    'state' => 'authenticator_verification_pending',
                    'nextStep' => 'verify_authenticator',
                    'message' => $message,
                    'errors' => [$message],
                    'redirect' => APP_BASE . '/account',
                ],
                422,
                $this->securityHeaders()
            );
        }

        $this->flash(
            'authenticator.disable',
            'errors',
            $message
        );

        return Response::redirect(
            APP_BASE . '/account/authenticator/disable',
            303
        );
    }

    private function expectsStructuredResponse(Request $request): bool
    {
        return str_contains(
            strtolower((string) $request->getHeader('Accept')),
            'application/json'
        ) || strtolower(
            (string) $request->getHeader('X-Requested-With')
        ) === 'xmlhttprequest';
    }

    private function securityHeaders(): array
    {
        return [
            'Cache-Control: no-store, private',
            'Pragma: no-cache',
            'Referrer-Policy: no-referrer',
        ];
    }
}
