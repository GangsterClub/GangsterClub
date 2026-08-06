<?php

declare(strict_types=1);

namespace app\Console;

use src\Data\Connection;
use src\Migration\AddBrowserSessionVersion;
use src\Migration\AddTwoStepVerificationPreference;
use src\Migration\CreateAuthenticationChallenge;
use src\Migration\CreateAuthenticationRateLimit;
use src\Migration\CreateEmailTOTP;
use src\Migration\CreateRecoveryCodes;
use src\Migration\CreateSecurityAuditEvent;
use src\Migration\CreateUser;
use src\Migration\CreateUserAuthenticatorTOTP;
use src\Migration\CreateUserEmailChange;

final class MigrationPipelineFactory
{
    public function create(): MigrationPipeline
    {
        $connection = new Connection();
        $pipeline = new MigrationPipeline($connection);

        $pipeline->addMigration(new CreateUser($connection));
        $pipeline->addMigration(new CreateEmailTOTP($connection));
        $pipeline->addMigration(new CreateUserAuthenticatorTOTP($connection));
        $pipeline->addMigration(new CreateUserEmailChange($connection));
        $pipeline->addMigration(new CreateAuthenticationChallenge($connection));
        $pipeline->addMigration(new CreateAuthenticationRateLimit($connection));
        $pipeline->addMigration(new CreateSecurityAuditEvent($connection));
        $pipeline->addMigration(new CreateRecoveryCodes($connection));
        $pipeline->addMigration(new AddBrowserSessionVersion($connection));
        $pipeline->addMigration(new AddTwoStepVerificationPreference($connection));

        return $pipeline;
    }
}
