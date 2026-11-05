<?php

declare(strict_types=1);

use app\Container\Container;
use src\Data\Repository\UserEmailChangeRepository;
use src\Identity\Profile\Application\ProfileManagement;
use src\Identity\Profile\Http\EmailChangeVerification;
use src\Identity\Profile\Http\ProfileFormHandler;
use src\Identity\Profile\ProfileServiceProvider;

require_once __DIR__ . '/../vendor/autoload.php';

function assertProfileArchitecture(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

$source = realpath(__DIR__ . '/../src');
assertProfileArchitecture(is_string($source), 'The source directory must be available.');
$profileDirectory = $source . '/Identity/Profile';

$application = (string) file_get_contents($profileDirectory . '/Application/ProfileManagement.php');
assertProfileArchitecture(
    preg_match('/app\\\\(?:Http|Container)\\\\|(?:^|[^A-Za-z])\\\\?Twig\\\\|src\\\\Controller\\\\/m', $application) !== 1,
    'Profile application code must not depend on HTTP, Twig, controllers, or the container.'
);

$controller = (string) file_get_contents($source . '/Controller/Account.php');
assertProfileArchitecture(
    str_contains($controller, 'ProfileManagement') === false
        && str_contains($controller, 'requestEmailChange') === false
        && str_contains($controller, 'confirmEmailChange') === false,
    'The mixed Account controller must delegate profile workflows through Profile HTTP collaborators.'
);
assertProfileArchitecture(
    is_file($source . '/Business/AccountService.php') === false,
    'The legacy cross-layer AccountService must remain removed.'
);

$domainProvider = (string) file_get_contents(__DIR__ . '/../app/Container/Provider/DomainServiceProvider.php');
assertProfileArchitecture(
    str_contains($domainProvider, 'UserEmailChangeRepository') === false
        && str_contains($domainProvider, 'ProfileManagement') === false,
    'Profile-owned registrations must not return to DomainServiceProvider.'
);

$routes = (string) file_get_contents($source . '/resources/routes.yaml');
assertProfileArchitecture(
    substr_count($routes, "controller: Account\n") === 1
        && substr_count($routes, 'controller: Account::verifyEmailChange') === 1,
    'Account and email-verification route ownership must remain unchanged.'
);

$controllerFiles = glob($source . '/Controller/*Controller.php');
$controllerFiles = $controllerFiles === false ? [] : $controllerFiles;
assertProfileArchitecture(
    array_map('basename', $controllerFiles) === ['Controller.php'],
    'Only the shared parent may use the redundant Controller.php suffix.'
);

$services = new Container();
$services->register(new ProfileServiceProvider());
foreach ([
    UserEmailChangeRepository::class,
    ProfileManagement::class,
    ProfileFormHandler::class,
    EmailChangeVerification::class,
] as $serviceId) {
    assertProfileArchitecture($services->has($serviceId), $serviceId . ' must be explicitly registered.');
}

fwrite(STDOUT, "Profile architecture tests passed.\n");
