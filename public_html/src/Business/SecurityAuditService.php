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
        if (preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $eventType) !== 1) {
            throw new \InvalidArgumentException('Security audit event names must use safe domain notation.');
        }

        $unknownKeys = array_diff(array_keys($context), self::ALLOWED_CONTEXT_KEYS);
        if ($unknownKeys !== []) {
            throw new \InvalidArgumentException('Security audit context contains a non-approved key.');
        }

        foreach ($context as $key => $value) {
            if (is_scalar($value) === false && $value !== null) {
                throw new \InvalidArgumentException('Security audit context values must be scalar.');
            }

            if (in_array($key, [
                'recovery_code_set_id',
                'replaces_recovery_code_set_id',
                'remaining_count',
                'authenticator_generation',
            ], true) === true && $value !== null && is_int($value) === false) {
                throw new \InvalidArgumentException('Security audit numeric context must use integers.');
            }

            if (in_array($key, ['purpose', 'reason', 'rate_limit_purpose'], true) === true
                && (is_string($value) === false
                    || preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $value) !== 1)
            ) {
                throw new \InvalidArgumentException('Security audit labels must use safe domain notation.');
            }
        }

        $contextJson = $context === []
            ? null
            : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->repository->insert(
            $eventType,
            $userId,
            $contextJson,
            $this->now()->format('Y-m-d H:i:s')
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock instanceof \Closure
            ? ($this->clock)()
            : new \DateTimeImmutable();
    }
}
