<?php

declare(strict_types=1);

namespace src\Business;

final class IssuedEmailTOTP
{
    public function __construct(public readonly int $id, public readonly string $code)
    {
    }
}
