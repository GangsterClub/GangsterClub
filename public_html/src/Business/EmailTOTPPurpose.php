<?php

declare(strict_types=1);

namespace src\Business;

enum EmailTOTPPurpose: string
{
    case LOGIN = 'login';
    case AUTHENTICATOR_ENROLLMENT = 'authenticator_enrollment';
    case LOST_AUTHENTICATOR_RECOVERY = 'lost_authenticator_recovery';

    public function rateLimitPurpose(): AuthenticationRateLimitPurpose
    {
        return match ($this) {
            self::LOGIN => AuthenticationRateLimitPurpose::EMAIL_TOTP_LOGIN,
            self::AUTHENTICATOR_ENROLLMENT => AuthenticationRateLimitPurpose::AUTHENTICATOR_ENROLLMENT,
            self::LOST_AUTHENTICATOR_RECOVERY => AuthenticationRateLimitPurpose::LOST_AUTHENTICATOR_RECOVERY,
        };
    }
}
