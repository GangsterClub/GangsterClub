<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use src\Business\EmailService;
use src\Business\MFATOTPService;
use src\Business\TOTPEmailService;
use src\Business\UserService;

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
            if ($rateLimit instanceof \app\Service\AuthRateLimitService && $rateLimit->allowAttempt('login', (string) $email, 5, 900) === false) {
                $this->flash('login', 'errors', __('error-email'));
                return $this->redirectSelf();
            }

            $auth->setPendingLoginEmail($email);
            $userService = new UserService($this->application);
            $user = $userService->getUserByEmail($email);
            if ($user === null) {
                $session = $this->application->get('sessionService');
                $user = $userService->createUserByEmail($email, $session->get('_IPaddress'));
            }

            $userId = (int) $user->getId();
            $auth->setPendingUserId($userId);
            $auth->setLoginMfaRequired(false);

            $mfaService = new MFATOTPService($this->application);
            $mfaEnabled = $mfaService->hasEnabledMfa($userId);
            if ($mfaEnabled === true) {
                $auth->setLoginMfaRequired(true);
                $this->flash('login', 'success', __('login.mfa-app-instructions', [
                    'digits' => (string) MFA_TOTP_DIGITS,
                    'period' => (string) MFA_TOTP_PERIOD,
                ]));
                return $this->redirectSelf();
            }

            return $this->sendEmailOtp($userId, $email, 'login', __('otp-email-sent'), __('error-email'));
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
        $submit = $request->post('submit_totp');
        $otp = $request->post('totp');

        if ((bool) $submit === true && (bool) $otp === true) {
            $otp = is_array($otp) === true ? implode('', $otp) : (string) $otp;
            $rateLimit = $this->application->get('authRateLimitService');
            $pendingEmail = $auth->getPendingLoginEmail();
            if ($rateLimit instanceof \app\Service\AuthRateLimitService && $pendingEmail !== null && $rateLimit->allowAttempt('otp', $pendingEmail, 5, 900) === false) {
                $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-invalid-otp'));
                return $this->redirectSelf();
            }

            $auth->setPendingLoginTotp($otp);
            $userId = $auth->getPendingUserId();

            if ($userId === null) {
                $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-invalid-otp'));
                return $this->redirectSelf();
            }

            $mfaRequired = $auth->isLoginMfaRequired();
            if ($mfaRequired === true) {
                $mfaService = new MFATOTPService($this->application);
                $isValid = $mfaService->verifyCode($userId, $otp);
            } else {
                $totpEmailService = new TOTPEmailService($this->application);
                $isValid = $totpEmailService->verifyEmailTOTP($userId, $otp);
            }

            if ((bool) $isValid === true) {
                $pendingEmail = $auth->getPendingLoginEmail();
                if ($pendingEmail === null) {
                    $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-invalid-otp'));
                    return $this->redirectSelf();
                }

                $auth->loginUser($userId);
                $this->flash('account', 'success', $this->translateForMode($mode, 'success-authenticated'));
                return Response::redirect(APP_BASE . '/account', 303);
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

        return [
            'email' => $auth->getPendingLoginEmail(),
            'uUID' => $auth->getPendingUserId(),
            'totp' => is_string($loginTotp) === true ? str_split($loginTotp) : [],
            'UID' => $auth->getAuthenticatedUserId(),
        ];
    }
}
