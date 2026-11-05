<?php

declare(strict_types=1);

namespace src\Identity\Profile;

use app\Container\Container;
use app\Container\ServiceProvider;
use app\Service\TranslationService;
use src\Business\EmailService;
use src\Business\UserService;
use src\Data\Connection;
use src\Data\Repository\UserEmailChangeRepository;
use src\Data\Repository\UserRepository;
use src\Identity\Profile\Application\ProfileManagement;
use src\Identity\Profile\Http\EmailChangeVerification;
use src\Identity\Profile\Http\ProfileFormHandler;
use Twig\Environment;

final class ProfileServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(UserEmailChangeRepository::class, fn(): UserEmailChangeRepository => new UserEmailChangeRepository(
            $container->get(Connection::class)
        ));
        $container->set(ProfileManagement::class, fn(): ProfileManagement => new ProfileManagement(
            $container->get(UserRepository::class),
            $container->get(UserEmailChangeRepository::class),
            $container->get(EmailService::class)
        ));
        $container->set(ProfileFormHandler::class, fn(): ProfileFormHandler => new ProfileFormHandler(
            $container->get(ProfileManagement::class),
            $container->get(UserService::class),
            $container->get(TranslationService::class)
        ));
        $container->set(
            EmailChangeVerification::class,
            fn(): EmailChangeVerification => new EmailChangeVerification(
                $container->get(ProfileManagement::class),
                $container->get(TranslationService::class),
                $container->get(Environment::class)
            )
        );
    }
}
