<?php

declare(strict_types=1);

namespace app\Service;

final class FlashBag
{
    private const SESSION_KEY = '_flash_messages';
    private const TYPES = ['errors', 'success'];

    public function __construct(private readonly SessionService $session)
    {
    }

    public function add(string $bag, string $type, string $message): void
    {
        if (in_array($type, self::TYPES, true) === false) {
            throw new \InvalidArgumentException('Unsupported flash message type.');
        }

        $messages = $this->all();
        $messages[$bag][$type][] = $message;
        $this->store($messages);
    }

    public function consume(string $bag): array
    {
        $messages = $this->all();
        $bagMessages = $messages[$bag] ?? [];
        unset($messages[$bag]);
        $this->store($messages);

        return [
            'errors' => is_array($bagMessages['errors'] ?? null) === true ? $bagMessages['errors'] : [],
            'success' => is_array($bagMessages['success'] ?? null) === true ? $bagMessages['success'] : [],
        ];
    }

    private function all(): array
    {
        $messages = $this->session->get(self::SESSION_KEY, []);
        return is_array($messages) === true ? $messages : [];
    }

    private function store(array $messages): void
    {
        if ($messages === []) {
            $this->session->remove(self::SESSION_KEY);
            return;
        }

        $this->session->set(self::SESSION_KEY, $messages);
    }
}
