<?php

/**
 * Summary of __
 * @param string $key
 * @param array $replacements
 * @return string
 */
function __(string $key, array $replacements = []): string
{
    global $app;
    static $translationService = null;

    if ($translationService === null) {
        $translationService = $app->get('translationService');
    }

    return $translationService->get($key, $replacements);
}

/**
 * Summary of translate
 * @param string $key
 * @param array $replacements
 * @return string
 */
function translate(string $key, array $replacements = []): string
{
    return __($key, $replacements);
}

/**
 * Summary of loadEnv
 * @param string $envFilePath
 * @throws \RuntimeException
 * @return void
 */
function loadEnv(string $envFilePath): void
{
    if (!file_exists($envFilePath)) {
        throw new \RuntimeException("Env file not found: " . htmlspecialchars($envFilePath));
    }

    $envContent = file($envFilePath, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

    foreach ($envContent as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) {
            continue;
        }

        [
            $key,
            $value,
        ] = array_map('trim', explode('=', $line, 2));
        if ((bool) preg_match('/^["\'](.*)["\']$/', $value, $matches) === true) {
            $value = $matches[1];
        }

        $lowerValue = strtolower($value);
        if ($lowerValue === 'true') {
            $value = true;
        } else if ($lowerValue === 'false') {
            $value = false;
        } else if ($lowerValue === 'null') {
            $value = null;
        }

        $value = preg_replace_callback(
            '/\$\{([A-Z_]+)\}/',
            fn($matches) => getenv($matches[1]) ?: $matches[0],
            $value
        );

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        define($key, $value);
        //$_SERVER[$key] = $value; // Optionally set in $_SERVER superglobal.
    }
}
