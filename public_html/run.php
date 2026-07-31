<?php

require_once __DIR__ . '/app/helper.functions.php';

$command = $argv[1] ?? null;
$commandsWithoutEnvironment = ['--setup', '--help'];
$envPath = __DIR__ . '/.env';

if (in_array($command, $commandsWithoutEnvironment, true) === false) {
    if (is_file($envPath) === false) {
        fwrite(STDERR, "Error: .env not found at {$envPath}." . PHP_EOL
            . "Run `php run.php --setup` first." . PHP_EOL
        );

        exit(1);
    }

    loadEnv(__DIR__ . '/.env');
}

require_once __DIR__ . '/app/console.php';
