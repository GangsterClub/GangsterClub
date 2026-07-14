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

class Login extends Controller
{
    private ?string $authorizationHeader = null;

    public function __invoke(Request $request): Response
    {
        $this->application->get('translationService')->setFile('login');
        $auth = $this->auth();

        $this->twigVariables['login'] = $this->consumeFlash('login');

        $loginResponse = $this->login($request, $auth);
        if ($loginResponse instanceof Response) {
            return $loginResponse;
        }

        $verifyResponse = $this->verify($request, $auth);
        if ($verifyResponse instanceof Response) {
            return $verifyResponse;
        }

        $email = $auth->getPendingLoginEmail();
        $pendingUserId = $auth->getPendingUserId();
        $loginTotp = $auth->getPendingLoginTotp();
        $totp = is_string($loginTotp) === true ? str_split($loginTotp) : [];
        $uid = $auth->getAuthenticatedUserId();

        $response = Response::html(
            $this->twig->render(
                'login.twig',
                array_merge(
                    $this->twigVariables,
                    [
                        'email' => $email,
                        'uUID' => $pendingUserId,
                        'totp' => $totp,
                        'UID' => $uid,
                    ]
                )
            )
        );

        if (is_string($this->authorizationHeader) === true && $this->authorizationHeader !== '') {
            return $response->withHeader($this->authorizationHeader);
        }

        return $response;
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

            $totpEmailService = new TOTPEmailService($this->application);
            $otp = $totpEmailService->generateEmailTOTP($userId);

            $emailService = new EmailService();
            $emailSent = $emailService->sendTOTPEmail($email, $otp);

            if ((bool) $emailSent === true) {
                $this->flash('login', 'success', __('otp-email-sent'));
                return $this->redirectSelf();
            }

            $this->flash('login', 'errors', __('error-email'));
            return $this->redirectSelf();
        }

        return null;
    }

    private function verify(Request $request, AuthService $auth): ?Response
    {
        $submit = $request->post('submit_totp');
        $otp = $request->post('totp');
        $jwtService = new JWTService($this->application);

        if ((bool) $submit === true && (bool) $otp === true) {
            $otp = is_array($otp) === true ? implode('', $otp) : (string) $otp;
            $auth->setPendingLoginTotp($otp);
            $userId = $auth->getPendingUserId();

            if ($userId === null) {
                $this->flash('login', 'errors', __('error-invalid-otp'));
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
                $this->flash('login', 'success', __('success-authenticated'));
                $pendingEmail = $auth->getPendingLoginEmail();
                if ($pendingEmail === null) {
                    $this->flash('login', 'errors', __('error-invalid-otp'));
                    return $this->redirectSelf();
                }

                $jwtToken = $jwtService->authenticate($pendingEmail, $isValid);
                if ($jwtToken !== false) {
                    $auth->loginUserWithToken($userId, $jwtToken);
                    $this->authorizationHeader = 'Authorization: Bearer ' . $jwtToken;
                }

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

            $this->flash('login', 'errors', __('error-invalid-otp'));
            return $this->redirectSelf();
        }

        return null;
    }
}
