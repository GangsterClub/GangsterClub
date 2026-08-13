<?php

declare(strict_types=1);

namespace src\Business;

enum AuthenticationRateLimitPurpose: string
{
    case EMAIL_TOTP_LOGIN = 'email_totp_login';
    case AUTHENTICATOR_LOGIN = 'authenticator_login';
    case AUTHENTICATOR_ENROLLMENT = 'authenticator_enrollment';
    case INITIAL_RECOVERY_CODES = 'initial_recovery_codes';
    case REPLACE_RECOVERY_CODES = 'replace_recovery_codes';
    case LOST_AUTHENTICATOR_RECOVERY = 'lost_authenticator_recovery';
    case AUTHENTICATOR_DISABLE = 'authenticator_disable';
}
