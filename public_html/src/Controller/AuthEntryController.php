<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use src\Business\AuthEntryService;

class AuthEntryController extends Controller
{
    private const MODE_LOGIN = 'login';
    private const MODE_REGISTER = 'register';
    private const REGISTER_FORM_VALUES = 'register.form_values';

    public function login(Request $request): Response
    {
        return $this->handle($request, self::MODE_LOGIN);
    }

    public function register(Request $request): Response
    {
        return $this->handle($request, self::MODE_REGISTER);
    }

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
                array_merge($this->twigVariables, $this->buildTwigVariables($auth, $mode))
            )
        );

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
            return $this->handleLoginFirstStep($request, $auth);
        }

        return $this->handleRegisterFirstStep($request, $auth);
    }

    private function handleLoginFirstStep(Request $request, AuthService $auth): ?Response
    {
        $submit = $request->post('submit_login');
        $email = $request->post('email');
        if ((bool) $submit === true && (bool) $email === true) {
            return $this->mapFirstStepResult(
                'login',
                $this->authEntryService()->beginLogin($auth, (string) $email)
            );
        }

        return null;
    }

    private function handleRegisterFirstStep(Request $request, AuthService $auth): ?Response
    {
        $submit = $request->post('submit_register');
        if ((bool) $submit !== true) {
            return null;
        }

        $username = (string) $request->post('username');
        $email = (string) $request->post('email');

        $result = $this->authEntryService()->beginRegistration(
            $auth,
            $username,
            $email
        );

        if (in_array(
            $result['status'] ?? null,
            [AuthEntryService::STATUS_VALIDATION_ERROR, AuthEntryService::STATUS_SEND_ERROR],
            true
        ) === true) {
            $this->rememberRegisterFormValues($username, $email);
        } else {
            $this->forgetRegisterFormValues();
        }

        return $this->mapFirstStepResult('register', $result);
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
            case AuthEntryService::STATUS_AUTHENTICATOR_CODE_REQUIRED:
                $this->flash('login', 'success', __('login.authenticator-app-instructions', [
                    'digits' => (string) AUTHENTICATOR_TOTP_DIGITS,
                    'period' => (string) AUTHENTICATOR_TOTP_PERIOD,
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
            case AuthEntryService::STATUS_AUTHENTICATED:
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


    private function rememberRegisterFormValues(string $username, string $email): void
    {
        $this->application->get('sessionService')->set(
            self::REGISTER_FORM_VALUES,
            base64_encode(json_encode(['username' => $username, 'email' => $email], JSON_THROW_ON_ERROR))
        );
    }

    private function consumeRegisterFormValues(): array
    {
        $session = $this->application->get('sessionService');
        $storedValues = $session->get(self::REGISTER_FORM_VALUES, '');
        $this->forgetRegisterFormValues();

        if (is_string($storedValues) === false || $storedValues === '') {
            return [];
        }

        $encodedValues = base64_decode($storedValues, true);
        if (is_string($encodedValues) === false) {
            return [];
        }

        $values = json_decode($encodedValues, true);
        if (is_array($values) === false) {
            return [];
        }

        return [
            'username' => is_string($values['username'] ?? null) === true ? $values['username'] : '',
            'email' => is_string($values['email'] ?? null) === true ? $values['email'] : null,
        ];
    }

    private function forgetRegisterFormValues(): void
    {
        $this->application->get('sessionService')->remove(self::REGISTER_FORM_VALUES);
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

    private function buildTwigVariables(AuthService $auth, string $mode): array
    {
        $loginTotp = $auth->getPendingLoginTotp();
        $pendingEmail = $auth->getPendingLoginEmail();
        $registerValues = ($mode === self::MODE_REGISTER && $pendingEmail === null) ?
            $this->consumeRegisterFormValues() :
            [];

        return [
            'email' => $pendingEmail ?? ($registerValues['email'] ?? null),
            'registerUsername' => $registerValues['username'] ?? '',
            'awaitingOtp' => $pendingEmail !== null,
            'uUID' => $auth->getPendingUserId(),
            'totp' => is_string($loginTotp) === true ? str_split($loginTotp) : [],
            'UID' => $auth->getAuthenticatedUserId(),
        ];
    }
}
