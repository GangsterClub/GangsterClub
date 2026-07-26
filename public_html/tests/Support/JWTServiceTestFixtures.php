<?php

declare(strict_types=1);

use app\Http\Request;
use app\Http\Response;
use app\Service\AuthService;
use app\Service\CsrfService;
use app\Service\JWT;
use app\Service\JWTService;
use app\Service\SessionService;
use src\Business\UserService;
use src\Entity\User;

final class JWTServiceTestSession extends SessionService
{
    public array $values = [];

    public function __construct()
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerate(): void
    {
    }
}

final class JWTServiceTestUserService extends UserService
{
    public function __construct(private readonly ?User $user = null)
    {
    }

    public function getUserById(int $userId): ?User
    {
        return ($this->user !== null && $userId === $this->user->getId()) === true ? $this->user : null;
    }
}

final class JWTServiceTestRequest extends Request
{
    public function __construct(
        private readonly array $testHeaders = [],
        private readonly array $testServer = [],
    ) {
    }

    public function getHeader(string $name): ?string
    {
        $value = $this->testHeaders[$name] ?? null;
        return is_string($value) === true ? $value : null;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->testServer[$key] ?? $default;
    }

    public function getServer(): array
    {
        return $this->testServer;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertAuthorizationSucceeded(Response|array $result, string $message): array
{
    if (is_array($result) === false) {
        throw new RuntimeException($message . ' Got response status ' . $result->getStatusCode() . '.');
    }
    return $result;
}

function makeJWTServiceTestContext(?User $authenticatedUser = null): array
{
    $session = new JWTServiceTestSession();
    $userService = new JWTServiceTestUserService($authenticatedUser);
    $authService = new AuthService($session, new CsrfService($session));
    $jwt = new JWT();
    return [$session, $authService, $jwt, new JWTService($jwt, $authService, $userService)];
}

function makeJWTServiceTestUser(int $id, string $email): User
{
    return new User($id, 'test-user', $email, '127.0.0.1', new DateTime(), new DateTime(), new DateTime());
}
