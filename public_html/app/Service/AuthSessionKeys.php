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

    private function __construct()
    {
    }
}
