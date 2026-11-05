<?php

declare(strict_types=1);

use app\Container\Container;
use src\Business\AuthEntryOtpService;
use src\Business\AuthEntryService;
use src\Controller\Login;
use src\Controller\Register;
use src\Identity\Authentication\AuthenticationServiceProvider;
use src\Identity\Authentication\Http\AuthEntryEndpoint;
use src\Identity\Authentication\Http\AuthEntryPageRenderer;
use src\Identity\Authentication\Http\AuthEntryRequestHandler;
use src\Identity\Authentication\Http\AuthEntryResponseMapper;
use src\Identity\Authentication\Http\AuthEntryViewModelFactory;
use src\Identity\Authentication\Infrastructure\SessionRegistrationFormState;

require_once __DIR__ . '/../vendor/autoload.php';

function assertAuthenticationArchitecture(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function authenticationPhpFiles(string $directory): array
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

function authenticationStub(string $className): object
{
    return (new ReflectionClass($className))->newInstanceWithoutConstructor();
}

$source = realpath(__DIR__ . '/../src');
assertAuthenticationArchitecture(is_string($source), 'The source directory must be available.');

foreach ([$source . '/Controller/Login.php', $source . '/Controller/Register.php'] as $file) {
    $contents = (string) file_get_contents($file);
    assertAuthenticationArchitecture(
        preg_match('/extends\s+Controller\b|app\\Container|\bApplication\b|\bContainer\b/', $contents) !== 1,
        basename($file) . ' must remain independent of the shared controller and container.'
    );
    assertAuthenticationArchitecture(
        preg_match('/new\s+(?:AuthEntry|SessionRegistration)/', $contents) !== 1,
        basename($file) . ' must only delegate to its injected endpoint.'
    );
}

$controllerFiles = glob($source . '/Controller/*Controller.php');
$controllerFiles = $controllerFiles === false ? [] : $controllerFiles;
assertAuthenticationArchitecture(
    array_map('basename', $controllerFiles) === ['Controller.php'],
    'Only the shared parent may use the redundant Controller.php suffix.'
);

$featureDirectory = $source . '/Identity/Authentication';
foreach (authenticationPhpFiles($featureDirectory) as $file) {
    if (basename($file) === 'AuthenticationServiceProvider.php') {
        continue;
    }
    $contents = (string) file_get_contents($file);
    assertAuthenticationArchitecture(
        preg_match('/app\\Container|\bApplication\b|\bContainer\b/', $contents) !== 1,
        basename($file) . ' must not access the application container.'
    );
}

foreach (authenticationPhpFiles($source . '/Business') as $file) {
    $contents = (string) file_get_contents($file);
    assertAuthenticationArchitecture(
        str_contains($contents, 'src\\Controller\\') === false
            && str_contains($contents, 'Twig\\') === false,
        basename($file) . ' must not depend on HTTP controllers or Twig.'
    );
}

$provider = $featureDirectory . '/AuthenticationServiceProvider.php';
foreach (authenticationPhpFiles($source) as $file) {
    if ($file === $provider) {
        continue;
    }
    $contents = (string) file_get_contents($file);
    assertAuthenticationArchitecture(
        preg_match('/new\s+(?:AuthEntryEndpoint|AuthEntryRequestHandler|AuthEntryResponseMapper|AuthEntryPageRenderer|AuthEntryViewModelFactory|SessionRegistrationFormState)\b/', $contents) !== 1,
        basename($file) . ' must not compose authentication feature services outside the provider.'
    );
}

assertAuthenticationArchitecture(
    is_file($source . '/Controller/AuthEntryController.php') === false
        && is_dir($source . '/Controller/AuthEntry') === false,
    'The legacy authentication controller hierarchy must remain deleted.'
);
foreach (authenticationPhpFiles($source) as $file) {
    assertAuthenticationArchitecture(
        str_contains((string) file_get_contents($file), 'src\\Controller\\AuthEntry') === false,
        basename($file) . ' must not reference the legacy authentication namespace.'
    );
}

$routes = (string) file_get_contents($source . '/resources/routes.yaml');
assertAuthenticationArchitecture(
    substr_count($routes, 'controller: Login::login') === 1
        && substr_count($routes, 'controller: Register::register') === 1,
    'Authentication routes must retain concise shorthand endpoint notation.'
);

$services = new Container();
$services->register(new AuthenticationServiceProvider());
$featureServices = [
    AuthEntryService::class,
    AuthEntryOtpService::class,
    SessionRegistrationFormState::class,
    AuthEntryRequestHandler::class,
    AuthEntryResponseMapper::class,
    AuthEntryViewModelFactory::class,
    AuthEntryPageRenderer::class,
    AuthEntryEndpoint::class,
    Login::class,
    Register::class,
];
foreach ($featureServices as $serviceId) {
    assertAuthenticationArchitecture($services->has($serviceId), $serviceId . ' must be explicitly registered.');
}

foreach ([
    \app\Service\AuthService::class,
    \app\Service\FlashBag::class,
    \app\Service\PendingAuthenticationSession::class,
    \app\Service\SecurityFlowSession::class,
    \app\Service\SessionService::class,
    \app\Service\TranslationService::class,
    \Twig\Environment::class,
    \src\Business\AuthEntryService::class,
    \src\Business\RecoveryFeatureService::class,
] as $serviceId) {
    $services->set($serviceId, authenticationStub($serviceId));
}
assertAuthenticationArchitecture(
    $services->make(Login::class) instanceof Login && $services->make(Register::class) instanceof Register,
    'Both authentication controllers must resolve through real feature-provider registration.'
);

echo "Authentication architecture tests passed.\n";
