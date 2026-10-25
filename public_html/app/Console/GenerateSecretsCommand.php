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
        $contents = $this->readEnvironment($envPath);

        foreach (self::SECRET_LENGTHS as $key => $bytes) {
            $contents = $this->replaceSecretIfNeeded($contents, $key, $bytes);
        }

        if (file_put_contents($envPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write .env.');
        }
    }

    private function readEnvironment(string $envPath): string
    {
        if (is_file($envPath) === false) {
            throw new RuntimeException('.env not found.');
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read .env.');
        }

        return $contents;
    }

    private function replaceSecretIfNeeded(string $contents, string $key, int $bytes): string
    {
        $pattern = '/^(\h*' . preg_quote($key, '/') . '\h*=\h*)([^\r\n]*)$/m';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            throw new RuntimeException("Missing {$key} in .env.");
        }

        $currentValue = trim($matches[2], " \t\n\r\0\x0B\"'");
        if ($this->requiresGeneration($key, $currentValue) === false) {
            fwrite(STDOUT, "Skipped {$key}: already configured." . PHP_EOL);
            return $contents;
        }

        $secret = bin2hex(random_bytes($bytes));
        $updated = preg_replace_callback(
            $pattern,
            static fn(array $match): string => $match[1] . '"' . $secret . '"',
            $contents,
            1
        );
        if ($updated === null) {
            throw new RuntimeException("Unable to update {$key}.");
        }

        fwrite(STDOUT, "Generated {$key}." . PHP_EOL);
        return $updated;
    }

    private function requiresGeneration(string $key, string $currentValue): bool
    {
        return $currentValue === ''
            || ($key === 'JWT_SECRET' && hash_equals(self::JWT_PLACEHOLDER, $currentValue));
    }
}
