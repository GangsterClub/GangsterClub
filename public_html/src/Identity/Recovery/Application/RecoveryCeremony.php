<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

enum RecoveryCeremony: string
{
    case ENROLLMENT = 'authenticator_enrollment';
    case INITIAL_CODES = 'initial_recovery_codes';
    case REPLACEMENT_CODES = 'replace_recovery_codes';
    case LOST_AUTHENTICATOR = 'lost_authenticator_recovery';
}
