<?php

declare(strict_types=1);

namespace src\Business;

enum AuthenticationRateLimitAction: string
{
    case EMAIL_TOTP_ISSUE = 'email_totp_issue';
    case EMAIL_TOTP_VERIFY = 'email_totp_verify';
    case RECOVERY_CODE_VERIFY = 'recovery_code_verify';
    case RECOVERY_CODE_GENERATE = 'recovery_code_generate';
}
