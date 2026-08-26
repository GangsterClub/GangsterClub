<?php

declare(strict_types=1);

namespace app\Console;

use app\Console\GenerateSecretsCommand;
use RuntimeException;

final class SetupCommand
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function execute(): int
    {
        try {
            $this->createEnvironmentFile();
            (new GenerateSecretsCommand())->generateSecrets(
                $this->projectRoot . '/.env'
            );

            fwrite(STDOUT, 'Setup completed successfully.' . PHP_EOL);

            return 0;
        } catch (RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private function createEnvironmentFile(): void
    {
        $sourcePath = $this->projectRoot . '/.env.example';
        $targetPath = $this->projectRoot . '/.env';

        if (is_file($targetPath) === true) {
            fwrite(STDOUT, '.env already exists; preserved.' . PHP_EOL);

            return;
        }

        if (is_file($sourcePath) === false) {
            throw new RuntimeException(
                'Unable to create .env: .env.example was not found.'
            );
        }

        if (is_readable($sourcePath) === false) {
            throw new RuntimeException(
                'Unable to create .env: .env.example is not readable.'
            );
        }

        $contents = file_get_contents($sourcePath);

        if ($contents === false) {
            throw new RuntimeException(
                'Unable to read .env.example.'
            );
        }

        /*
         * Mode "x" creates the file only if it does not already exist.
         * This prevents accidentally overwriting a file created between
         * the initial existence check and this write.
         */
        $handle = fopen($targetPath, 'x');

        if ($handle === false) {
            if (is_file($targetPath) === true) {
                fwrite(STDOUT, '.env already exists; preserved.' . PHP_EOL);

                return;
            }

            throw new RuntimeException(
                'Unable to create .env. Check directory permissions.'
            );
        }

        try {
            $bytesWritten = fwrite($handle, $contents);

            if ($bytesWritten !== strlen($contents)) {
                throw new RuntimeException(
                    'Unable to write the complete .env file.'
                );
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($targetPath);

            throw $exception;
        }

        fclose($handle);

        /*
         * Best-effort restrictive permissions on Unix-like systems.
         * Windows may ignore this.
         */
        @chmod($targetPath, 0600);

        fwrite(STDOUT, 'Created .env from .env.example.' . PHP_EOL);
    }
}
