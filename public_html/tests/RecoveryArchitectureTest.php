<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

function assertRecoveryArchitecture(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

function recoveryPhpFiles(string $directory): array
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
assertRecoveryArchitecture(is_string($source), 'The source directory must be available.');
$legacyClasses = [
    'RecoveryControllerComponent',
    'RecoveryStartHandler',
    'RecoveryFlowHandler',
    'RecoveryVerificationActions',
    'RecoveryPresentationActions',
    'AccountRecoveryCodesController',
    'LostAuthenticatorRecoveryController',
    'StartInitialRecoveryCodes',
];
foreach ($legacyClasses as $class) {
    assertRecoveryArchitecture(
        is_file($source . '/Controller/' . $class . '.php') === false,
        $class . ' must not return to the legacy controller hierarchy.'
    );
}

$applicationFiles = recoveryPhpFiles($source . '/Identity/Recovery/Application');
foreach ($applicationFiles as $file) {
    $contents = (string) file_get_contents($file);
    assertRecoveryArchitecture(
        preg_match('/app\\\\(?:Http|Container)\\\\|(?:^|[^A-Za-z])\\\\?Twig\\\\|src\\\\Controller\\\\/m', $contents) !== 1,
        basename($file) . ' must not depend on HTTP, Twig, controllers, or the container.'
    );
}

$controllerFiles = [
    $source . '/Controller/RecoveryStartController.php',
    $source . '/Controller/RecoveryFlowController.php',
];
foreach ($controllerFiles as $file) {
    $contents = (string) file_get_contents($file);
    assertRecoveryArchitecture(
        preg_match('/app\\\\Container|\$this->application|new\\s+(?:RecoveryFlowCoordinator|RecoveryVerification|RecoveryCodeLifecycle|StartAccountRecovery|StartLostAuthenticatorRecovery)\\b/', $contents) !== 1,
        basename($file) . ' must use injected recovery collaborators.'
    );
}

$routes = (string) file_get_contents($source . '/resources/routes.yaml');
$expectedRoutes = [
    'RecoveryFlowController::account',
    'RecoveryStartController::enroll',
    'RecoveryStartController::initial',
    'RecoveryStartController::replace',
    'RecoveryStartController::lost',
    'RecoveryFlowController::lost',
];
foreach ($expectedRoutes as $controller) {
    assertRecoveryArchitecture(
        substr_count($routes, 'controller: ' . $controller) === 1,
        $controller . ' must own exactly one recovery route.'
    );
}

$startController = new ReflectionClass(src\Controller\RecoveryStartController::class);
$ceremonyActions = [
    src\Identity\Recovery\Application\RecoveryCeremony::ENROLLMENT->value => 'enroll',
    src\Identity\Recovery\Application\RecoveryCeremony::INITIAL_CODES->value => 'initial',
    src\Identity\Recovery\Application\RecoveryCeremony::REPLACEMENT_CODES->value => 'replace',
    src\Identity\Recovery\Application\RecoveryCeremony::LOST_AUTHENTICATOR->value => 'lost',
];
foreach ($ceremonyActions as $ceremony => $action) {
    assertRecoveryArchitecture($startController->hasMethod($action), $ceremony . ' must have a start action.');
}

$mapper = new ReflectionClass(src\Identity\Recovery\Http\RecoveryResponseMapper::class);
$definitions = $mapper->getReflectionConstant('DEFINITIONS')->getValue();
foreach (src\Identity\Recovery\Application\RecoveryOutcome::cases() as $outcome) {
    if (in_array($outcome, [
        src\Identity\Recovery\Application\RecoveryOutcome::UNAUTHENTICATED,
        src\Identity\Recovery\Application\RecoveryOutcome::SHOW_FLOW,
    ], true) === true) {
        continue;
    }
    assertRecoveryArchitecture(
        array_key_exists($outcome->value, $definitions),
        $outcome->value . ' must have an HTTP response definition.'
    );
}

echo "Recovery architecture tests passed.\n";
