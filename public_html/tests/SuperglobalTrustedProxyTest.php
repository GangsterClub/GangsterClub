<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Http/Superglobal.php';

use app\Http\Superglobal;

define('REQUEST_METHOD', 'GET');

function effectiveRemoteAddress(string $remoteAddress, string $trustedProxies, string $clientIp): mixed
{
    putenv('CLIENT_IP_HEADER=X-Real-IP');
    putenv("CLIENT_IP_TRUSTED_PROXIES={$trustedProxies}");
    $_SERVER['REMOTE_ADDR'] = $remoteAddress;
    $_SERVER['HTTP_X_REAL_IP'] = $clientIp;

    return (new Superglobal())->server('REMOTE_ADDR');
}

$untrustedAddress = effectiveRemoteAddress('198.51.100.20', '192.0.2.10', '203.0.113.5');
if ($untrustedAddress !== '198.51.100.20') {
    throw new RuntimeException('An untrusted peer was allowed to override REMOTE_ADDR.');
}

$trustedAddress = effectiveRemoteAddress('192.0.2.10', '192.0.2.9, 192.0.2.10', '203.0.113.5');
if ($trustedAddress !== '203.0.113.5') {
    throw new RuntimeException('A trusted proxy could not provide the client address.');
}

$invalidAddress = effectiveRemoteAddress('192.0.2.10', '192.0.2.10', 'not-an-ip');
if ($invalidAddress !== '192.0.2.10') {
    throw new RuntimeException('An invalid client address was accepted.');
}

fwrite(STDOUT, "Superglobal trusted proxy test passed.\n");
