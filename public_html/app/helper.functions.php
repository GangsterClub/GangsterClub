<?php

function __(string $key, array $replacements = []): string
{
    global $app;
    static $translationService = null;

    if ($translationService === null) {
        $translationService = $app->get(\app\Service\TranslationService::class);
    }

    return $translationService->get($key, $replacements);
}

function translate(string $key, array $replacements = []): string
{
    return __($key, $replacements);
}

function loadEnv(string $envFilePath): void
{
    static $loaded = [];

    $resolvedPath = realpath($envFilePath);
    $resolvedPath = $resolvedPath !== false ? $resolvedPath : $envFilePath;
    if (isset($loaded[$resolvedPath]) === true) {
        return;
    }

    if ((bool) file_exists($envFilePath) === false) {
        throw new \RuntimeException("Env file not found: " . htmlspecialchars($envFilePath, ENT_QUOTES, 'UTF-8'));
    }

    $envContent = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envContent as $line) {
        $parsed = parseEnvLine(trim($line));
        if ($parsed === null) {
            continue;
        }

        assignEnvValue($parsed['key'], $parsed['value']);
    } //end foreach

    $loaded[$resolvedPath] = true;
}

function parseEnvLine(string $line): ?array
{
    if (strpos($line, '#') === 0) {
        return null;
    }

    [$key, $value] = array_map('trim', explode('=', $line, 2));
    return [
        'key' => $key,
        'value' => normalizeEnvValue(expandEnvReferences(unquoteEnvValue($value))),
    ];
}

function unquoteEnvValue(string $value): string
{
    if (preg_match('/^["\'](.*)["\']$/', $value, $matches) === 1) {
        return $matches[1];
    }

    return $value;
}

function expandEnvReferences(string $value): string
{
    return preg_replace_callback(
        '/\$\{([A-Z_]+)\}/',
        static function (array $matches): string {
            $environmentValue = getenv($matches[1]);
            return $environmentValue !== false && (bool) $environmentValue === true
                ? $environmentValue
                : $matches[0];
        },
        $value
    ) ?? $value;
}

function normalizeEnvValue(string $value): string|int|bool|null
{
    $lowerValue = strtolower($value);
    if ($lowerValue === 'true') {
        return true;
    }
    if ($lowerValue === 'false') {
        return false;
    }
    if ($lowerValue === 'null') {
        return null;
    }
    if (is_numeric($lowerValue) === true) {
        return (int) $lowerValue;
    }

    return $value;
}

function assignEnvValue(string $key, string|int|bool|null $value): void
{
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    define($key, $value);
}

function seoUrl($string = ""): string
{
    $string = strtolower((string) $string);
    $unwanted_array = array(
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'A',
        'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ñ' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
        'Š' => 'S',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ý' => 'Y',
        'Ž' => 'Z',
        'Þ' => 'B',
        'ß' => 'Ss',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ð' => 'o', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'š' => 's',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u',
        'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y',
        'ž' => 'z',
        'Ã«' => 'e'
    );
    $string = strtr($string, $unwanted_array);
    $string = preg_replace("/[^a-z0-9_\s-]/", "", $string);
    $string = preg_replace("/[\s-]+/", " ", $string);
    $string = preg_replace("/[\s_]/", "-", $string);
    return $string;
} //end function
