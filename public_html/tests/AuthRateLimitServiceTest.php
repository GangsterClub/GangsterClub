<?php

declare(strict_types=1);

namespace Tests;

use app\Container\Application;
use app\Service\AuthRateLimitService;
use PHPUnit\Framework\TestCase;

final class AuthRateLimitServiceTest extends TestCase
{
    public function testAttemptsAreTrackedAndBlockedAfterTheLimit(): void
    {
        $session = new TestSession();
        $application = new TestApplication($session);
        $throttle = new AuthRateLimitService($application);

        $this->assertTrue($throttle->allowAttempt('login', 'alice@example.com', 3, 300));
        $this->assertTrue($throttle->allowAttempt('login', 'alice@example.com', 3, 300));
        $this->assertTrue($throttle->allowAttempt('login', 'alice@example.com', 3, 300));
        $this->assertFalse($throttle->allowAttempt('login', 'alice@example.com', 3, 300));

        $throttle->reset('login', 'alice@example.com');
        $this->assertTrue($throttle->allowAttempt('login', 'alice@example.com', 3, 300));
    }
}

final class TestSession
{
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->store[$key]);
    }
}

final class TestApplication extends Application
{
    public function __construct(private readonly object $session)
    {
    }

    public function get(string $name): ?object
    {
        if ($name === 'sessionService') {
            return $this->session;
        }

        return null;
    }
}
