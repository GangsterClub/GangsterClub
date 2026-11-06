<?php

declare(strict_types=1);

use app\Container\Container;
use src\Controller\AuthenticatorSecurity;
use src\Identity\AccountSecurity\AccountSecurityServiceProvider;
use src\Identity\AccountSecurity\Application\DisableAuthenticator;
use src\Identity\AccountSecurity\Application\UpdateTwoStepPreference;
use src\Identity\AccountSecurity\Http\AccountSecurityFormHandler;
use src\Identity\AccountSecurity\Http\AuthenticatorDisableEndpoint;

require_once __DIR__ . '/../vendor/autoload.php';

function assertAccountSecurityArchitecture(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function accountSecurityPhpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() === true && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

$source = realpath(__DIR__ . '/../src');
assertAccountSecurityArchitecture(is_string($source), 'The source directory must be available.');
$feature = $source . '/Identity/AccountSecurity';

foreach (accountSecurityPhpFiles($feature . '/Application') as $file) {
    $contents = (string) file_get_contents($file);
    assertAccountSecurityArchitecture(
        preg_match('/app\\\\(?:Http|Container)\\\\|(?:^|[^A-Za-z])\\\\?Twig\\\\|src\\\\Controller\\\\|src\\\\Identity\\\\Recovery\\\\/m', $contents) !== 1,
        basename($file) . ' must not depend on HTTP, Twig, controllers, the container, or Recovery internals.'
    );
}

foreach (accountSecurityPhpFiles($feature . '/Http') as $file) {
    $contents = (string) file_get_contents($file);
    assertAccountSecurityArchitecture(
        preg_match('/app\\\\Container|\$this->application|src\\\\Controller\\\\/', $contents) !== 1,
        basename($file) . ' must use injected collaborators without controller or container access.'
    );
}

$controller = (string) file_get_contents($source . '/Controller/AuthenticatorSecurity.php');
assertAccountSecurityArchitecture(
    preg_match('/app\\\\Container|extends\s+Controller|\$this->application/', $controller) !== 1
        && str_contains($controller, 'AuthenticatorDisableEndpoint'),
    'AuthenticatorSecurity must remain a thin injected route delegate.'
);

$account = (string) file_get_contents($source . '/Controller/Account.php');
assertAccountSecurityArchitecture(
    str_contains($account, 'AccountSecurityFormHandler')
        && str_contains($account, 'setTwoStepVerificationRequired') === false
        && str_contains($account, 'submit_two_step') === false,
    'Account must delegate security form mutations to the feature handler.'
);

$routes = (string) file_get_contents($source . '/resources/routes.yaml');
assertAccountSecurityArchitecture(
    substr_count($routes, 'controller: AuthenticatorSecurity::startDisable') === 1
        && substr_count($routes, 'controller: AuthenticatorSecurity::disable') === 1,
    'Authenticator-disable routes must remain unchanged.'
);

$application = (string) file_get_contents(__DIR__ . '/../app/Container/Application.php');
assertAccountSecurityArchitecture(
    substr_count($application, 'new AccountSecurityServiceProvider()') === 1,
    'The application must register AccountSecurity exactly once.'
);

foreach (accountSecurityPhpFiles($feature) as $file) {
    if (basename($file) === 'AccountSecurityServiceProvider.php') {
        continue;
    }
    assertAccountSecurityArchitecture(
        str_contains((string) file_get_contents($file), "src\\Identity\\Recovery\\") === false,
        basename($file) . ' must not take ownership of Recovery internals.'
    );
}

$services = new Container();
$services->register(new AccountSecurityServiceProvider());
foreach ([
    DisableAuthenticator::class,
    UpdateTwoStepPreference::class,
    AccountSecurityFormHandler::class,
    AuthenticatorDisableEndpoint::class,
    AuthenticatorSecurity::class,
] as $serviceId) {
    assertAccountSecurityArchitecture($services->has($serviceId), $serviceId . ' must be explicitly registered.');
}

fwrite(STDOUT, "Account security architecture tests passed.\n");
