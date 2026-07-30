<?php

declare(strict_types=1);

namespace app\Service;

final class AuthSessionKeys
{
    public const AUTHENTICATED_USER_ID = 'UID';
    public const PENDING_USER_ID = 'UNAUTHENTICATED_UID';
    public const PENDING_LOGIN_EMAIL = 'login.email';
    public const PENDING_LOGIN_TOTP = 'login.totp';
    public const LOGIN_AUTHENTICATOR_REQUIRED = 'login.authenticator_required';
    public const JWT_TOKEN = 'jwt_token';
    public const PENDING_AUTHENTICATOR_SECRET = 'account.authenticator.secret';
    public const AUTHENTICATOR_SETUP_EMAIL_SECRET = 'account.authenticator.email_totp_secret';
    public const SECURITY_CHALLENGE_TOKEN = 'security.challenge.token';
    public const SECURITY_CHALLENGE_PURPOSE = 'security.challenge.purpose';
    public const SECURITY_CHALLENGE_BINDING = 'security.challenge.binding';
    public const SECURITY_PENDING_RECOVERY_SET_ID = 'security.recovery_set.id';
    public const SECURITY_RECOVERY_CODES_DELIVERED = 'security.recovery_codes.delivered_set_id';
    public const SECURITY_EMAIL_SECRET = 'security.email_totp_secret';
    public const BROWSER_SESSION_VERSION = 'security.browser_session_version';

    private function __construct()
    {
    }
}
