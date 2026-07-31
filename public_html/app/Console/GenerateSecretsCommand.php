<?php

declare(strict_types=1);

namespace app\Console;

use RuntimeException;

final class GenerateSecretsCommand
{
    private const JWT_PLACEHOLDER =
        'LOCAL_DEV_ONLY_PLACEHOLDER_SECRET_KEY_DO_NOT_USE_IN_PRODUCTION_1234567890';

    private const SECRET_LENGTHS = [
        'JWT_SECRET' => 64,
        'AUTH_CHALLENGE_PEPPER' => 32,
        'AUTH_RATE_LIMIT_PEPPER' => 32,
        'RECOVERY_CODE_PEPPER' => 32,
    ];

    public function generateSecrets(string $envPath): void
    {
        if (is_file($envPath) === false) {
            throw new RuntimeException('.env not found.');
        }

        $contents = file_get_contents($envPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read .env.');
        }

        foreach (self::SECRET_LENGTHS as $key => $bytes) {
            $pattern = '/^(\h*' . preg_quote($key, '/') . '\h*=\h*)([^\r\n]*)$/m';

            if (preg_match($pattern, $contents, $matches) !== 1) {
                throw new RuntimeException("Missing {$key} in .env.");
            }

            $currentValue = trim($matches[2], " \t\n\r\0\x0B\"'");

            $shouldGenerate =
                $currentValue === ''
                || (
                    $key === 'JWT_SECRET'
                    && hash_equals(self::JWT_PLACEHOLDER, $currentValue)
                );

            if ((bool) $shouldGenerate === false) {
                fwrite(STDOUT, "Skipped {$key}: already configured." . PHP_EOL);
                continue;
            }

            $secret = bin2hex(random_bytes($bytes));

            $contents = preg_replace_callback(
                $pattern,
                static fn(array $match): string =>
                    $match[1] . '"' . $secret . '"',
                $contents,
                1
            );

            if ($contents === null) {
                throw new RuntimeException("Unable to update {$key}.");
            }

            fwrite(STDOUT, "Generated {$key}." . PHP_EOL);
        }

        if (file_put_contents($envPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write .env.');
        }
    }
}
