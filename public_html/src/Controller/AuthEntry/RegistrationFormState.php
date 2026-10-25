<?php

declare(strict_types=1);

namespace src\Controller\AuthEntry;

use app\Service\SessionService;

final class RegistrationFormState
{
    private const SESSION_KEY = 'register.form_values';

    public function __construct(private readonly SessionService $session) {}

    public function remember(string $username, string $email): void
    {
        $this->session->set(self::SESSION_KEY, ['username' => $username, 'email' => $email]);
    }

    public function consume(): array
    {
        $stored = $this->session->get(self::SESSION_KEY, []);
        $this->forget();
        if (is_array($stored) === false) {
            return [];
        }
        return [
            'username' => is_string($stored['username'] ?? null) === true ? $stored['username'] : '',
            'email' => is_string($stored['email'] ?? null) === true ? $stored['email'] : null,
        ];
    }

    public function forget(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
