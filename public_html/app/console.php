<?php

require_once __DIR__ . '/../vendor/autoload.php';

use app\Console\SetupCommand;
use app\Console\MigrationPipelineFactory;

const COMMAND_MIGRATE = '--migrate';
const COMMAND_ROLLBACK = '--rollback';
const COMMAND_SETUP = '--setup';
const COMMAND_HELP = '--help';

$command = $argv[1] ?? null;

switch ($command) {
    case COMMAND_SETUP:
        new SetupCommand(__DIR__ . '/../')->execute();
        break;

    case COMMAND_MIGRATE:
    case '-m':
        $pipeline = (new MigrationPipelineFactory())->create();
        $pipeline->migrate();

        fwrite(STDOUT, "Migrations applied successfully." . PHP_EOL);
        break;

    case COMMAND_ROLLBACK:
    case '-r':
        $pipeline = (new MigrationPipelineFactory())->create();
        $pipeline->rollback();

        fwrite(STDOUT, "Migrations rolled back successfully." . PHP_EOL);
        break;
    
    case COMMAND_HELP:
    default:
        fwrite(
            STDERR,
            "Usage: php run.php [--setup|--migrate, -m|--rollback, -r|--help]"
            . PHP_EOL
        );
        exit(1);
}
