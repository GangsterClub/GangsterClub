<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use app\Service\AuthRateLimitService;
use app\Service\AuthService;
use src\Business\AuthEntryService;

class AuthEntryController extends Controller
{
    private const MODE_LOGIN = 'login';
    private const MODE_REGISTER = 'register';

    private ?string $authorizationHeader = null;

    public function handle(Request $request, string $mode): Response
    {
        $this->assertValidMode($mode);

        $this->application->get('translationService')->setFile($mode);
        $auth = $this->auth();

        if ($auth->getAuthenticatedUserId() !== null) {
            return Response::redirect(APP_BASE . '/account', 303);
        }

        $this->twigVariables[$mode] = $this->consumeFlash($mode);

        $firstStepResponse = $this->handleFirstStep($request, $auth, $mode);
        if ($firstStepResponse instanceof Response) {
            return $firstStepResponse;
        }

        $verifyResponse = $this->verify($request, $auth, $mode);
        if ($verifyResponse instanceof Response) {
            return $verifyResponse;
        }

        $response = Response::html(
            $this->twig->render(
                $mode . '.twig',
                array_merge($this->twigVariables, $this->buildTwigVariables($auth))
            )
        );

        if (is_string($this->authorizationHeader) === true && $this->authorizationHeader !== '') {
            return $response->withHeader($this->authorizationHeader);
        }

        return $response;
    }

    private function assertValidMode(string $mode): void
    {
        if (in_array($mode, [self::MODE_LOGIN, self::MODE_REGISTER], true) === false) {
            throw new \InvalidArgumentException('Unsupported auth entry mode: ' . $mode);
        }
    }

    private function handleFirstStep(Request $request, AuthService $auth, string $mode): ?Response
    {
        if ($mode === self::MODE_LOGIN) {
            return $this->login($request, $auth);
        }

        return $this->register($request, $auth);
    }

    private function login(Request $request, AuthService $auth): ?Response
    {
        $submit = $request->post('submit_login');
        $email = $request->post('email');
        if ((bool) $submit === true && (bool) $email === true) {
            $rateLimit = $this->application->get('authRateLimitService');
            if ($rateLimit instanceof AuthRateLimitService && $rateLimit->allowAttempt('login', (string) $email, 5, 900) === false) {
                $this->flash('login', 'errors', __('error-email'));
                return $this->redirectSelf();
            }

            return $this->mapFirstStepResult(
                'login',
                $this->authEntryService()->beginLogin($auth, (string) $email)
            );
        }

        return null;
    }

    private function register(Request $request, AuthService $auth): ?Response
    {
        $submit = $request->post('submit_register');
        if ((bool) $submit !== true) {
            return null;
        }

        return $this->mapFirstStepResult(
            'register',
            $this->authEntryService()->beginRegistration(
                $auth,
                (string) $request->post('username'),
                (string) $request->post('email')
            )
        );
    }

    private function verify(Request $request, AuthService $auth, string $mode): ?Response
    {
        $submit = $request->post('submit_totp');
        $otp = $request->post('totp');

        if ((bool) $submit === true && (bool) $otp === true) {
            $otp = is_array($otp) === true ? implode('', $otp) : (string) $otp;
            $rateLimit = $this->application->get('authRateLimitService');
            $pendingEmail = $auth->getPendingLoginEmail();
            if ($rateLimit instanceof AuthRateLimitService && $pendingEmail !== null && $rateLimit->allowAttempt('otp', $pendingEmail, 5, 900) === false) {
                $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-invalid-otp'));
                return $this->redirectSelf();
            }

            return $this->mapVerifyResult($mode, $this->authEntryService()->verify($auth, $mode, $otp));
        }

        return null;
    }

    private function mapFirstStepResult(string $mode, array $result): Response
    {
        switch ($result['status'] ?? null) {
            case AuthEntryService::STATUS_APP_MFA_REQUIRED:
                $this->flash('login', 'success', __('login.mfa-app-instructions', [
                    'digits' => (string) MFA_TOTP_DIGITS,
                    'period' => (string) MFA_TOTP_PERIOD,
                ]));
                break;
            case AuthEntryService::STATUS_EMAIL_OTP_SENT:
                $this->flash($mode, 'success', $this->translateForMode($mode, 'otp-email-sent'));
                break;
            case AuthEntryService::STATUS_SEND_ERROR:
                $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-email'));
                break;
            case AuthEntryService::STATUS_VALIDATION_ERROR:
                $this->flash($mode, 'errors', $this->validationErrorMessage($result['error'] ?? '', $mode));
                break;
        }

        return $this->redirectSelf();
    }

    private function mapVerifyResult(string $mode, array $result): Response
    {
        switch ($result['status'] ?? null) {
            case AuthEntryService::STATUS_AUTHORIZATION_RESPONSE:
                return $result['response'];
            case AuthEntryService::STATUS_AUTHENTICATED:
                $jwtToken = (string) ($result['jwtToken'] ?? '');
                if ($jwtToken !== '') {
                    $this->authorizationHeader = 'Authorization: Bearer ' . $jwtToken;
                }
                $this->flash('account', 'success', $this->translateForMode($mode, 'success-authenticated'));
                return Response::redirect(APP_BASE . '/account', 303);
            default:
                $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-invalid-otp'));
                return $this->redirectSelf();
        }
    }

    private function validationErrorMessage(string $error, string $mode): string
    {
        return match ($error) {
            'provide-valid-email' => $this->translateForMode($mode, 'provide-valid-email-address'),
            'provide-valid-username' => __('provide-valid-username'),
            'duplicate-email' => __('email-address-already-in-use'),
            'duplicate-username' => __('username-already-in-use'),
            default => $this->translateForMode($mode, 'error-email'),
        };
    }

    protected function authEntryService(): AuthEntryService
    {
        $authEntryService = $this->application->get('authEntryService');
        if (($authEntryService instanceof AuthEntryService) === false) {
            throw new \RuntimeException('Auth entry service is not available.');
        }

        return $authEntryService;
    }

    private function translateForMode(string $mode, string $key): string
    {
        if ($mode === self::MODE_REGISTER) {
            return __('login.' . $key);
        }

        return __($key);
    }

    private function buildTwigVariables(AuthService $auth): array
    {
        $loginTotp = $auth->getPendingLoginTotp();
        $pendingEmail = $auth->getPendingLoginEmail();

        return [
            'email' => $pendingEmail,
            'uUID' => $auth->getPendingUserId(),
            'totp' => is_string($loginTotp) === true ? str_split($loginTotp) : [],
            'UID' => $auth->getAuthenticatedUserId(),
            'awaitingOtp' => $pendingEmail !== null,
        ];
    }
}
