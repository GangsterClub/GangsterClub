<?php

declare(strict_types=1);

namespace src\Business;

enum AuthenticatorTOTPPurpose: string
{
    case LOGIN = 'login';
    case AUTHENTICATOR_ENROLLMENT = 'authenticator_enrollment';
    case LOST_AUTHENTICATOR_RECOVERY = 'lost_authenticator_recovery';
    case INITIAL_RECOVERY_CODES = 'initial_recovery_codes';
    case REPLACE_RECOVERY_CODES = 'replace_recovery_codes';
    case AUTHENTICATOR_DISABLE = 'authenticator_disable';

    public function rateLimitPurpose(): AuthenticationRateLimitPurpose
    {
        return match ($this) {
            self::LOGIN => AuthenticationRateLimitPurpose::AUTHENTICATOR_LOGIN,
            self::AUTHENTICATOR_ENROLLMENT => AuthenticationRateLimitPurpose::AUTHENTICATOR_ENROLLMENT,
            self::LOST_AUTHENTICATOR_RECOVERY => AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY,
            self::INITIAL_RECOVERY_CODES => AuthenticationRateLimitPurpose::INITIAL_RECOVERY_CODES,
            self::REPLACE_RECOVERY_CODES => AuthenticationRateLimitPurpose::REPLACE_RECOVERY_CODES,
            self::AUTHENTICATOR_DISABLE => AuthenticationRateLimitPurpose::AUTHENTICATOR_DISABLE,
        };
    }
}
