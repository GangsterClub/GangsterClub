<?php

declare(strict_types=1);

namespace src\Identity\AccountSecurity;

use app\Container\Container;
use app\Container\ServiceProvider;
use app\Service\AuthService;
use app\Service\FlashBag;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use app\Service\TranslationService;
use src\Business\AuthenticatorTOTPService;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;
use src\Controller\AuthenticatorSecurity;
use src\Identity\AccountSecurity\Application\DisableAuthenticator;
use src\Identity\AccountSecurity\Application\UpdateTwoStepPreference;
use src\Identity\AccountSecurity\Http\AccountSecurityFormHandler;
use src\Identity\AccountSecurity\Http\AuthenticatorDisableEndpoint;
use Twig\Environment;

final class AccountSecurityServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(DisableAuthenticator::class, fn(): DisableAuthenticator => new DisableAuthenticator(
            $container->get(AuthenticatorTOTPService::class),
            $container->get(RecoveryFeatureService::class),
            $container->get(SessionService::class),
            $container->get(SecurityFlowSession::class),
            $container->get(AuthService::class)
        ));
        $container->set(UpdateTwoStepPreference::class, fn(): UpdateTwoStepPreference => new UpdateTwoStepPreference(
            $container->get(AuthenticatorTOTPService::class),
            $container->get(UserService::class)
        ));
        $container->set(AccountSecurityFormHandler::class, fn(): AccountSecurityFormHandler => new AccountSecurityFormHandler(
            $container->get(UpdateTwoStepPreference::class),
            $container->get(TranslationService::class)
        ));
        $container->set(AuthenticatorDisableEndpoint::class, fn(): AuthenticatorDisableEndpoint => new AuthenticatorDisableEndpoint(
            $container->get(AuthService::class),
            $container->get(DisableAuthenticator::class),
            $container->get(FlashBag::class),
            $container->get(SessionService::class),
            $container->get(TranslationService::class),
            $container->get(Environment::class)
        ));
        $container->set(AuthenticatorSecurity::class, fn(): AuthenticatorSecurity => new AuthenticatorSecurity(
            $container->get(AuthenticatorDisableEndpoint::class)
        ));
    }
}
