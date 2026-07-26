<?php

declare(strict_types=1);

if (isset($argv[1]) === false) {
    foreach (['production', 'testing', 'development'] as $scenario) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario) . ' 2>&1';
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException("SessionService constructor scenario {$scenario} failed:\n" . implode("\n", $output));
        }
    }

    fwrite(STDOUT, "SessionService constructor tests passed.\n");
    exit(0);
}

$scenario = $argv[1];
$configuration = match ($scenario) {
    'production' => ['production', false, '10.0.0.1', null],
    'testing' => ['testing', false, '10.0.0.1', '10.0.0.1'],
    'development' => ['production', true, '10.0.0.1', '10.0.0.1'],
    default => throw new InvalidArgumentException("Unknown scenario: {$scenario}"),
};

define('ENVIRONMENT', $configuration[0]);
define('DEVELOPMENT', $configuration[1]);

require_once __DIR__ . '/../app/Http/Superglobal.php';
require_once __DIR__ . '/../app/Http/Request.php';
require_once __DIR__ . '/../app/Service/SessionService.php';

final class SessionConstructorTestRequest extends app\Http\Request
{
    public function __construct(private readonly string $remoteAddress)
    {
    }

    public function server(string $key, $default = null): mixed
    {
        return $key === 'REMOTE_ADDR' ? $this->remoteAddress : $default;
    }
}

$service = new app\Service\SessionService(new SessionConstructorTestRequest($configuration[2]));
$ipAddress = (new ReflectionProperty(app\Service\SessionService::class, 'ipAddress'))->getValue($service);

if ($ipAddress !== $configuration[3]) {
    throw new RuntimeException(
        "Scenario {$scenario} expected " . var_export($configuration[3], true) . ', got ' . var_export($ipAddress, true)
    );
}

fwrite(STDOUT, "SessionService constructor scenario {$scenario} passed.\n");
