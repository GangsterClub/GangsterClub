<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
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

            if ((bool) $emailSent === true) {
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
        $jwtService = new JWTService($this->application);
        if ((bool) $submit === true && (bool) $otp === true) {
            $otp = is_array($otp) === true ? implode('', $otp) : (string) $otp;
            $session->set('login.totp', $otp);
            $userId = (int) $session->get('UNAUTHENTICATED_UID');

            if ($userId === null) {
                $this->redirectPrevRoute($request);
            }

            $totpEmailService = new TOTPEmailService($this->application);
            $isValid = $totpEmailService->verifyEmailTOTP($userId, $otp);

            if ((bool) $isValid === true) {
                $this->twigVariables['login']['success'][] = __('success-authenticated');
                $jwtToken = $jwtService->authenticate($session->get('login.email'), $isValid);
                if ($jwtToken !== false) {
                    $session->set('jwt_token', $jwtToken);
                    header('Authorization: Bearer ' . $jwtToken);
                }
                return;
            }

            $storedToken = $session->get('jwt_token');
            if (is_string($storedToken) === true && $storedToken !== '') {
                $authorizationResult = $jwtService->authorize('Bearer ' . $storedToken);
                if ($authorizationResult instanceof Response) {
                    $authorizationResult->send();
                    return;
                }

                if (is_array($authorizationResult) === true && isset($authorizationResult['token'])) {
                    $session->set('jwt_token', $authorizationResult['token']);
                }
            }

            $this->twigVariables['login']['errors'][] = __('error-invalid-otp');
        } //end if
    }
}
