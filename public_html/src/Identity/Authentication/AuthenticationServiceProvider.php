<?php

declare(strict_types=1);

namespace src\Identity\Authentication;

use app\Container\Container;
use app\Container\ServiceProvider;
use app\Service\AuthService;
use app\Service\FlashBag;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use app\Service\TranslationService;
use src\Business\AuthenticationRateLimitContextProvider;
use src\Business\AuthenticationRateLimitService;
use src\Business\AuthenticatorTOTPService;
use src\Business\AuthEntryOtpService;
use src\Business\AuthEntryService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Business\TOTPService;
use src\Business\UserService;
use src\Controller\Login;
use src\Controller\Register;
use src\Identity\Authentication\Http\AuthEntryEndpoint;
use src\Identity\Authentication\Http\AuthEntryPageRenderer;
use src\Identity\Authentication\Http\AuthEntryRequestHandler;
use src\Identity\Authentication\Http\AuthEntryResponseMapper;
use src\Identity\Authentication\Http\AuthEntryViewModelFactory;
use src\Identity\Authentication\Infrastructure\SessionRegistrationFormState;
use Twig\Environment;

final class AuthenticationServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $this->registerBusiness($container);
        $this->registerHttp($container);
        $this->registerControllers($container);
    }

    private function registerBusiness(Container $container): void
    {
        $container->set(AuthEntryOtpService::class, fn(): AuthEntryOtpService => new AuthEntryOtpService(
            $container->get(EmailTOTPService::class),
            $container->get(TOTPService::class),
            $container->get(EmailService::class),
            $container->get(SessionService::class),
            $container->get(AuthenticationRateLimitService::class),
            $container->get(AuthenticationRateLimitContextProvider::class)
        ));
        $container->set(AuthEntryService::class, fn(): AuthEntryService => new AuthEntryService(
            $container->get(UserService::class),
            $container->get(AuthenticatorTOTPService::class),
            $container->get(EmailTOTPService::class),
            $container->get(EmailService::class),
            $container->get(SessionService::class),
            $container->get(RecoveryCodeService::class),
            $container->get(PendingAuthenticationSession::class),
            $container->get(AuthEntryOtpService::class),
            $container->get(AuthenticationRateLimitContextProvider::class)
        ));
    }

    private function registerHttp(Container $container): void
    {
        $container->set(
            SessionRegistrationFormState::class,
            fn(): SessionRegistrationFormState => new SessionRegistrationFormState(
                $container->get(SessionService::class)
            )
        );
        $container->set(AuthEntryRequestHandler::class, fn(): AuthEntryRequestHandler => new AuthEntryRequestHandler(
            $container->get(AuthEntryService::class),
            $container->get(AuthService::class),
            $container->get(PendingAuthenticationSession::class),
            $container->get(SecurityFlowSession::class),
            $container->get(SessionService::class),
            $container->get(RecoveryFeatureService::class),
            $container->get(SessionRegistrationFormState::class)
        ));
        $container->set(AuthEntryResponseMapper::class, fn(): AuthEntryResponseMapper => new AuthEntryResponseMapper(
            $container->get(FlashBag::class),
            $container->get(TranslationService::class)
        ));
        $container->set(
            AuthEntryViewModelFactory::class,
            fn(): AuthEntryViewModelFactory => new AuthEntryViewModelFactory(
                $container->get(PendingAuthenticationSession::class),
                $container->get(RecoveryFeatureService::class),
                $container->get(SessionRegistrationFormState::class)
            )
        );
        $container->set(AuthEntryPageRenderer::class, fn(): AuthEntryPageRenderer => new AuthEntryPageRenderer(
            $container->get(Environment::class)
        ));
        $container->set(AuthEntryEndpoint::class, fn(): AuthEntryEndpoint => new AuthEntryEndpoint(
            $container->get(TranslationService::class),
            $container->get(AuthService::class),
            $container->get(FlashBag::class),
            $container->get(AuthEntryRequestHandler::class),
            $container->get(AuthEntryResponseMapper::class),
            $container->get(AuthEntryViewModelFactory::class),
            $container->get(AuthEntryPageRenderer::class)
        ));
    }

    private function registerControllers(Container $container): void
    {
        $container->set(Login::class, fn(): Login => new Login($container->get(AuthEntryEndpoint::class)));
        $container->set(Register::class, fn(): Register => new Register($container->get(AuthEntryEndpoint::class)));
    }
}
