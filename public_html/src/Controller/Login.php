<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Service\SessionService;
use app\Service\JWTService;
use src\Business\UserService;
use src\Business\TOTPEmailService;
use src\Business\EmailService;

class Login extends Controller
{
    /**
     * Summary of __invoke
     * @param \app\Http\Request $request
     * @return string
     */
    public function __invoke(Request $request): string
    {
        $this->application->get('translationService')->setFile('login');
        $session = $this->application->get('sessionService');
        $this->login($request, $session);
        $this->verify($request, $session);
        $email = $session->get('login.email');
        $uUID = $session->get('UNAUTHENTICATED_UID');
        $loginTotp = $session->get('login.totp');
        $totp = is_array($loginTotp) === false ? str_split((string) $loginTotp) : (array) $loginTotp;
        $uid = $session->get('UID');

        return $this->twig->render(
            'login.twig',
            array_merge(
                $this->twigVariables,
                [
                    'email' => $email,
                    'uUID' => $uUID,
                    'totp' => $totp,
                    'UID' => $uid
                ]
            )
        );
    }

    /**
     * Summary of login
     * @param \app\Http\Request $request
     * @param \app\Service\SessionService $session
     * @return void
     */
    private function login(Request $request, SessionService $session): void
    {
        $submit = $request->post('submit_login');
        $email = $request->post('email');
        if ((bool) $submit === true && (bool) $email === true) {
            $session->set('login.email', $email);
            $userService = new UserService($this->application);
            $user = $userService->getUserByEmail($email);
            if ($user === null) {
                $user = $userService->createUserByEmail($email, $session->get('_IPaddress'));
            }

            $userId = (int) $user->getId();
            $session->set('UNAUTHENTICATED_UID', $userId);

            $totpEmailService = new TOTPEmailService($this->application);
            $otp = $totpEmailService->generateEmailTOTP($userId);

            $emailService = new EmailService();
            $emailSent = $emailService->sendTOTPEmail($email, $otp);

            if ($emailSent) {
                $this->redirectPrevRoute($request);
                return;
            }

            $this->twigVariables['login']['errors'][] = __('error-email');
        } //end if
    }

    /**
     * Summary of verify
     * @param \app\Http\Request $request
     * @param \app\Service\SessionService $session
     * @return void
     */
    private function verify(Request $request, SessionService $session): void
    {
        $submit = $request->post('submit_totp');
        $otp = $request->post('totp');
        if ((bool) $submit === true && (bool) $otp === true) {
            $otp = is_array($otp) ? implode('', $otp) : (string) $otp;
            $session->set('login.totp', $otp);
            $userId = (int) $session->get('UNAUTHENTICATED_UID');

            if ($userId === null) {
                $this->redirectPrevRoute($request);
            }

            $totpEmailService = new TOTPEmailService($this->application);
            $isValid = $totpEmailService->verifyEmailTOTP($userId, $otp);

            if ((bool) $isValid === true) {
                $this->twigVariables['login']['success'][] = __('success-authenticated');
                // Grant valid JWT token bearing username 'login.email' to client for 360 seconds
                $jwtService = new JWTService($this->application);
                $jwtToken = $jwtService->authenticate($session->get('login.email'), $isValid);
                $session->set('jwt_token', $jwtToken);
                // The following comments asume the login page is not a protected resource
                // Auth ok resource example:
                //header('Authorization: Bearer ' . $jwtToken);
                return;
            }
            // Auth nok resource example:
            //header('HTTP/1.1 401 Unauthorized');
            //header('WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="Invalid access token"');

            $this->twigVariables['login']['errors'][] = __('error-invalid-otp');
        } //end if
    }
}
