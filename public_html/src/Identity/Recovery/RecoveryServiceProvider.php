<?php

declare(strict_types=1);

namespace src\Identity\Recovery;

use app\Container\Container;
use app\Container\ServiceProvider;
use app\Service\AuthService;
use app\Service\FlashBag;
use app\Service\PendingAuthenticationSession;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use app\Service\TranslationService;
use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Business\RecoveryFlowService;
use src\Business\UserService;
use src\Controller\RecoveryFlow;
use src\Controller\RecoveryStart;
use src\Identity\Recovery\Application\AbandonRecoveryFlow;
use src\Identity\Recovery\Application\RecoveryEmailVerification;
use src\Identity\Recovery\Application\RecoveryCodeLifecycle;
use src\Identity\Recovery\Application\RecoveryFlowCoordinator;
use src\Identity\Recovery\Application\RecoveryFlowState;
use src\Identity\Recovery\Application\RecoveryIdentityContext;
use src\Identity\Recovery\Application\RecoveryRateLimitContextFactory;
use src\Identity\Recovery\Application\RecoveryVerification;
use src\Identity\Recovery\Application\StartAccountRecovery;
use src\Identity\Recovery\Application\StartLostAuthenticatorRecovery;
use src\Identity\Recovery\Http\RecoveryPageRenderer;
use src\Identity\Recovery\Http\RecoveryResponseMapper;
use src\Identity\Recovery\Infrastructure\SessionRecoveryFlowState;
use src\Identity\Recovery\Infrastructure\SessionRecoveryIdentityContext;
use src\Identity\Recovery\Infrastructure\SessionRecoveryRateLimitContextFactory;

final class RecoveryServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $this->registerInfrastructure($container);
        $this->registerApplication($container);
        $this->registerHttp($container);
    }

    private function registerInfrastructure(Container $container): void
    {
        $container->set(RecoveryFlowState::class, fn(): RecoveryFlowState => new SessionRecoveryFlowState(
            $container->get(SecurityFlowSession::class)
        ));
        $container->set(RecoveryIdentityContext::class, fn(): RecoveryIdentityContext => new SessionRecoveryIdentityContext(
            $container->get(AuthService::class),
            $container->get(PendingAuthenticationSession::class)
        ));
        $container->set(
            RecoveryRateLimitContextFactory::class,
            fn(): RecoveryRateLimitContextFactory => new SessionRecoveryRateLimitContextFactory(
                $container->get(SessionService::class),
                $container->get(RecoveryFlowState::class)
            )
        );
    }

    private function registerApplication(Container $container): void
    {
        $container->set(AbandonRecoveryFlow::class, fn(): AbandonRecoveryFlow => new AbandonRecoveryFlow(
            $container->get(RecoveryCodeService::class),
            $container->get(AuthenticationChallengeService::class),
            $container->get(RecoveryFlowState::class)
        ));
        $container->set(
            RecoveryEmailVerification::class,
            fn(): RecoveryEmailVerification => new RecoveryEmailVerification(
                $container->get(EmailTOTPService::class),
                $container->get(EmailService::class),
                $container->get(AuthenticatorTOTPService::class),
                $container->get(AuthenticationChallengeService::class),
                $container->get(RecoveryFlowState::class),
                $container->get(RecoveryRateLimitContextFactory::class)
            )
        );
        $container->set(StartAccountRecovery::class, fn(): StartAccountRecovery => new StartAccountRecovery(
            $container->get(UserService::class),
            $container->get(RecoveryFeatureService::class),
            $container->get(AuthenticatorTOTPService::class),
            $container->get(AuthenticationChallengeService::class),
            $container->get(RecoveryFlowState::class),
            $container->get(RecoveryIdentityContext::class),
            $container->get(AbandonRecoveryFlow::class),
            $container->get(RecoveryEmailVerification::class)
        ));
        $container->set(
            StartLostAuthenticatorRecovery::class,
            fn(): StartLostAuthenticatorRecovery => new StartLostAuthenticatorRecovery(
                $container->get(UserService::class),
                $container->get(RecoveryFeatureService::class),
                $container->get(AuthenticationChallengeService::class),
                $container->get(RecoveryFlowState::class),
                $container->get(RecoveryIdentityContext::class),
                $container->get(AbandonRecoveryFlow::class),
                $container->get(RecoveryEmailVerification::class)
            )
        );
        $container->set(RecoveryCodeLifecycle::class, fn(): RecoveryCodeLifecycle => new RecoveryCodeLifecycle(
            $container->get(AuthenticationChallengeService::class),
            $container->get(RecoveryCodeService::class),
            $container->get(RecoveryFeatureService::class),
            $container->get(RecoveryFlowState::class),
            $container->get(RecoveryIdentityContext::class),
            $container->get(RecoveryRateLimitContextFactory::class),
            $container->get(EmailService::class)
        ));
        $this->registerFlowApplication($container);
    }

    private function registerFlowApplication(Container $container): void
    {
        $container->set(RecoveryVerification::class, fn(): RecoveryVerification => new RecoveryVerification(
            $container->get(RecoveryEmailVerification::class),
            $container->get(AuthenticatorTOTPService::class),
            $container->get(RecoveryFlowService::class),
            $container->get(AuthenticationChallengeService::class),
            $container->get(RecoveryFlowState::class),
            $container->get(RecoveryRateLimitContextFactory::class),
            $container->get(RecoveryCodeLifecycle::class)
        ));
        $container->set(RecoveryFlowCoordinator::class, fn(): RecoveryFlowCoordinator => new RecoveryFlowCoordinator(
            $container->get(UserService::class),
            $container->get(RecoveryFlowService::class),
            $container->get(RecoveryFlowState::class),
            $container->get(RecoveryIdentityContext::class),
            $container->get(AbandonRecoveryFlow::class),
            $container->get(RecoveryVerification::class),
            $container->get(RecoveryCodeLifecycle::class)
        ));
    }

    private function registerHttp(Container $container): void
    {
        $container->set(RecoveryPageRenderer::class, fn(): RecoveryPageRenderer => new RecoveryPageRenderer(
            $container->get(\Twig\Environment::class),
            $container->get(AuthenticatorTOTPService::class),
            $container->get(RecoveryFeatureService::class),
            $container->get(RecoveryFlowState::class),
            $container->get(FlashBag::class)
        ));
        $container->set(RecoveryResponseMapper::class, fn(): RecoveryResponseMapper => new RecoveryResponseMapper(
            $container->get(FlashBag::class),
            $container->get(TranslationService::class),
            $container->get(RecoveryPageRenderer::class)
        ));
        $container->set(RecoveryStart::class, fn(): RecoveryStart => new RecoveryStart(
            $container->get(StartAccountRecovery::class),
            $container->get(StartLostAuthenticatorRecovery::class),
            $container->get(RecoveryResponseMapper::class)
        ));
        $container->set(RecoveryFlow::class, fn(): RecoveryFlow => new RecoveryFlow(
            $container->get(RecoveryFlowCoordinator::class),
            $container->get(RecoveryResponseMapper::class)
        ));
    }
}
