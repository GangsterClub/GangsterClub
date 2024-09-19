<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Business\SessionService;
use app\Business\UserService;
use app\Business\TOTPEmailService;
use app\Business\EmailService;

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
        $totp = $session->get('login.totp');

        return $this->twig->render(
            'login.twig',
            array_merge(
                $this->twigVariables,
                [
                    'email' => $email,
                    'uUID' => $uUID,
                    'totp' => $totp,
                ]
            )
        );
    }

    /**
     * Summary of login
     * @param \app\Http\Request $request
     * @param \app\Business\SessionService $session
     * @return void
     */
    private function login(Request $request, SessionService $session)
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
            } else {
                $this->twigVariables['login']['errors'][] = __('error-email');
            }
        }
    }

    /**
     * Summary of verify
     * @param \app\Http\Request $request
     * @param \app\Business\SessionService $session
     * @return void
     */
    private function verify(Request $request, SessionService $session): void
    {
        $submit = $request->post('submit_totp');
        $otp = $request->post('totp');
        if ((bool) $submit === true && (bool) $otp === true) {
            $session->set('login.totp', $otp);
            $userId = (int) $session->get('UNAUTHENTICATED_UID');

            if ($userId === null) {
                $this->redirectPrevRoute($request);
            }

            $totpEmailService = new TOTPEmailService($this->application);
            $isValid = $totpEmailService->verifyEmailTOTP($userId, $otp);

            if ((bool) $isValid === true) {
                $this->twigVariables['login']['success'][] = __('success-authenticated');
                // Proceed with user authentication
                //..
                return;
            }

            $this->twigVariables['login']['errors'][] = __('error-invalid-otp');
        }
    }
}