<?php

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\SecurityAuditEventRepository;

class SecurityAuditService
{
    private const ALLOWED_CONTEXT_KEYS = [
        'recovery_code_set_id',
        'replaces_recovery_code_set_id',
        'remaining_count',
        'purpose',
        'reason',
        'authenticator_generation',
        'rate_limit_purpose',
    ];

    public function __construct(
        private readonly SecurityAuditEventRepository $repository,
        private readonly ?\Closure $clock = null
    ) {
    }

    public function record(string $eventType, ?int $userId = null, array $context = []): void
    {
        $this->validateEventType($eventType);
        $this->validateContext($context);

        $this->repository->insert(
            $eventType,
            $userId,
            $this->encodeContext($context),
            $this->now()->format('Y-m-d H:i:s')
        );
    }

    private function validateEventType(string $eventType): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $eventType) !== 1) {
            throw new \InvalidArgumentException('Security audit event names must use safe domain notation.');
        }
    }

    private function validateContext(array $context): void
    {
        $unknownKeys = array_diff(array_keys($context), self::ALLOWED_CONTEXT_KEYS);
        if ($unknownKeys !== []) {
            throw new \InvalidArgumentException('Security audit context contains a non-approved key.');
        }

        foreach ($context as $key => $value) {
            $this->validateContextValue((string) $key, $value);
        }
    }

    private function validateContextValue(string $key, mixed $value): void
    {
        if (is_scalar($value) === false && $value !== null) {
            throw new \InvalidArgumentException('Security audit context values must be scalar.');
        }
        if ($this->isNumericContextKey($key) === true && $value !== null && is_int($value) === false) {
            throw new \InvalidArgumentException('Security audit numeric context must use integers.');
        }
        if ($this->isLabelContextKey($key) === true && $this->isValidLabel($value) === false) {
            throw new \InvalidArgumentException('Security audit labels must use safe domain notation.');
        }
    }

    private function isNumericContextKey(string $key): bool
    {
        return in_array($key, [
            'recovery_code_set_id',
            'replaces_recovery_code_set_id',
            'remaining_count',
            'authenticator_generation',
        ], true);
    }

    private function isLabelContextKey(string $key): bool
    {
        return in_array($key, ['purpose', 'reason', 'rate_limit_purpose'], true);
    }

    private function isValidLabel(mixed $value): bool
    {
        return is_string($value) === true
            && preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $value) === 1;
    }

    private function encodeContext(array $context): ?string
    {
        return $context === []
            ? null
            : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock instanceof \Closure
            ? ($this->clock)()
            : new \DateTimeImmutable();
    }
}
