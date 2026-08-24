<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryAuthenticatorVerificationResult
{
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const WARNING_PRESENTED = 'warning_presented';
    public const GENERATE_CODES = 'generate_codes';

    public function __construct(
        public readonly string $status,
        public readonly ?object $challenge = null,
        public readonly ?string $expectedState = null
    ) {
    }
}
