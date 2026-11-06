<?php

declare(strict_types=1);

namespace src\Identity\AccountSecurity\Application;

enum TwoStepPreferenceOutcome: string
{
    case AUTHENTICATOR_REQUIRED = 'authenticator_required';
    case ERROR = 'error';
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';
}
