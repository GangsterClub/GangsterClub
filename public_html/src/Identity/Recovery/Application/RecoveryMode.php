<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

enum RecoveryMode: string
{
    case ACCOUNT = 'account';
    case LOST_AUTHENTICATOR = 'lost_authenticator';
}
