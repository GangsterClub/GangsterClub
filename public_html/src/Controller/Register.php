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

class Register extends Controller
{
    private ?string $authorizationHeader = null;

    public function __invoke(Request $request): Response
    {
        $this->application->get('translationService')->setFile('login');
        $auth = $this->auth();

        if ($auth->getAuthenticatedUserId() !== null) {
            return Response::redirect(APP_BASE . '/account', 303);
        }

        $this->twigVariables['register'] = $this->consumeFlash('register');

        $registerResponse = $this->register($request, $auth);
        if ($registerResponse instanceof Response) {
            return $registerResponse;
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
                'register.twig',
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
            $this->flash('register', 'errors', __('provide-valid-email-address'));
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
            $this->flash('register', 'errors', __('error-email'));
            return $this->redirectSelf();
        }

        $userId = (int) $user->getId();
        $auth->setPendingLoginEmail($email);
        $auth->setPendingUserId($userId);
        $auth->setLoginMfaRequired(false);

        $totpEmailService = new TOTPEmailService($this->application);
        $otp = $totpEmailService->generateEmailTOTP($userId);

        $emailService = new EmailService();
        $emailSent = $emailService->sendTOTPEmail($email, $otp);

        if ((bool) $emailSent === true) {
            $this->flash('register', 'success', __('otp-email-sent'));
            return $this->redirectSelf();
        }

        $this->flash('register', 'errors', __('error-email'));
        return $this->redirectSelf();
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
                $this->flash('register', 'errors', __('error-invalid-otp'));
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
                    $this->flash('register', 'errors', __('error-invalid-otp'));
                    return $this->redirectSelf();
                }

                $jwtToken = $jwtService->authenticate($pendingEmail, $isValid);
                if ($jwtToken !== false) {
                    $auth->loginUserWithToken($userId, $jwtToken);
                    $this->authorizationHeader = 'Authorization: Bearer ' . $jwtToken;
                    $this->flash('account', 'success', __('success-authenticated'));
                    return Response::redirect(APP_BASE . '/account', 303);
                }

                $this->flash('register', 'errors', __('error-invalid-otp'));
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

            $this->flash('register', 'errors', __('error-invalid-otp'));
            return $this->redirectSelf();
        }

        return null;
    }
}
