<?php

declare(strict_types=1);

namespace src\Business;

enum AuthenticationRateLimitAction: string
{
    case RECOVERY_CODE_VERIFY = 'recovery_code_verify';
    case RECOVERY_CODE_GENERATE = 'recovery_code_generate';
}
