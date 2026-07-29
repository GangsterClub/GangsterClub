<?php

declare(strict_types=1);

namespace src\Business;

final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $purpose,
        public readonly int $retryAfterSeconds
    ) {
        parent::__construct('The security operation is rate limited.');
    }
}
