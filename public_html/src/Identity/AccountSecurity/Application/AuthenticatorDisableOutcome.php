<?php

declare(strict_types=1);

namespace src\Identity\AccountSecurity\Application;

enum AuthenticatorDisableOutcome: string
{
    case CODE_REQUIRED = 'code_required';
    case INVALID_CODE = 'invalid_code';
    case ERROR = 'error';
    case DISABLED = 'disabled';
}
