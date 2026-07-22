<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use app\Service\JWTService;
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

        $username = trim((string) $request->post('username'));
        $email = trim((string) $request->post('email'));
        $userService = new UserService($this->application);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('register', 'errors', __('login.provide-valid-email-address'));
            return $this->redirectSelf();
        }

        if ($username === '') {
            $this->flash('register', 'errors', __('provide-valid-username'));
            return $this->redirectSelf();
        }

        if ($userService->getUserByEmail($email) !== null) {
            $this->flash('register', 'errors', __('email-address-already-in-use'));
            return $this->redirectSelf();
        }

        if ($userService->getUserByUsername($username) !== null) {
            $this->flash('register', 'errors', __('username-already-in-use'));
            return $this->redirectSelf();
        }

        $session = $this->application->get('sessionService');
        $user = $userService->createUser($username, $email, $session->get('_IPaddress'));
        if ($user === null) {
            $this->flash('register', 'errors', __('login.error-email'));
            return $this->redirectSelf();
        }

        $userId = (int) $user->getId();
        $auth->setPendingLoginEmail($email);
        $auth->setPendingUserId($userId);
        $auth->setLoginMfaRequired(false);

        return $this->sendEmailOtp($userId, $email, 'register', __('login.otp-email-sent'), __('login.error-email'));
    }

    private function sendEmailOtp(
        int $userId,
        string $email,
        string $flashScope,
        string $successMessage,
        string $errorMessage
    ): Response {
        $totpEmailService = new TOTPEmailService($this->application);
        $otp = $totpEmailService->generateEmailTOTP($userId);

        $emailService = new EmailService();
        $emailSent = $emailService->sendTOTPEmail($email, $otp);

        if ((bool) $emailSent === true) {
            $this->flash($flashScope, 'success', $successMessage);
            return $this->redirectSelf();
        }

        $this->flash($flashScope, 'errors', $errorMessage);
        return $this->redirectSelf();
    }

    private function verify(Request $request, AuthService $auth, string $mode): ?Response
    {
        $submit = $request->post('submit_totp');
        $otp = $request->post('totp');
        $jwtService = new JWTService($this->application);

        if ((bool) $submit === true && (bool) $otp === true) {
            $otp = is_array($otp) === true ? implode('', $otp) : (string) $otp;
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

                $jwtToken = $jwtService->authenticate($pendingEmail, $isValid);
                if ($jwtToken !== false) {
                    $auth->loginUserWithToken($userId, $jwtToken);
                    $this->authorizationHeader = 'Authorization: Bearer ' . $jwtToken;
                    $this->flash('account', 'success', $this->translateForMode($mode, 'success-authenticated'));
                    return Response::redirect(APP_BASE . '/account', 303);
                }

                $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-invalid-otp'));
                return $this->redirectSelf();
            }

            $storedToken = $auth->getStoredJwtToken();
            if (is_string($storedToken) === true && $storedToken !== '') {
                $authorizationResult = $jwtService->authorize('Bearer ' . $storedToken);
                if ($authorizationResult instanceof Response) {
                    return $authorizationResult;
                }

                if (is_array($authorizationResult) === true && isset($authorizationResult['token']) === true) {
                    $auth->storeJwtToken($authorizationResult['token']);
                }
            }

            $this->flash($mode, 'errors', $this->translateForMode($mode, 'error-invalid-otp'));
            return $this->redirectSelf();
        }

        return null;
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
