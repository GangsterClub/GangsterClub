<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

enum RecoveryAction: string
{
    case VERIFY_EMAIL = 'verify_email';
    case VERIFY_AUTHENTICATOR = 'verify_authenticator';
    case VERIFY_RECOVERY_CODE = 'verify_recovery_code';
    case CONFIRM_REPLACEMENT = 'confirm_replacement';
    case ACKNOWLEDGE = 'acknowledge';
    case CODES_UNAVAILABLE = 'codes_unavailable';
    case CANCEL = 'cancel';
}
