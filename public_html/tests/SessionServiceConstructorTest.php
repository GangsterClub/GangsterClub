<?php

declare(strict_types=1);

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

$service = new app\Service\SessionService(new SessionConstructorTestRequest('10.0.0.1'));
$ipAddress = (new ReflectionProperty(app\Service\SessionService::class, 'ipAddress'))->getValue($service);

if ($ipAddress !== $expectedIpAddress) {
    throw new RuntimeException(
        'Expected ' . var_export($expectedIpAddress, true) . ', got ' . var_export($ipAddress, true)
    );
}

fwrite(STDOUT, "SessionService constructor {$scenarioName} test passed.\n");
