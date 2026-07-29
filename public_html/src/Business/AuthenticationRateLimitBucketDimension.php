<?php

declare(strict_types=1);

namespace src\Business;

enum AuthenticationRateLimitBucketDimension: string
{
    case ACCOUNT = 'account';
    case IP_ADDRESS = 'ip';
    case SESSION = 'session';
    case CHALLENGE = 'challenge';
}
