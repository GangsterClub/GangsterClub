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
    if ((bool) file_exists($envFilePath) === false) {
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

        $value = preg_replace_callback(
            '/\$\{([A-Z_]+)\}/',
            fn($matches) => getenv($matches[1]) ?: $matches[0],
            $value
        );

        $lowerValue = strtolower($value);
        if ($lowerValue === 'true') {
            $value = true;
        } else if ($lowerValue === 'false') {
            $value = false;
        } else if ($lowerValue === 'null') {
            $value = null;
        } else if ((bool) is_numeric($lowerValue) === true) {
            $value = (int) $lowerValue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        define($key, $value);
        //$_SERVER[$key] = $value; // Optionally set in $_SERVER superglobal.
    } //end foreach
}

function seoUrl($string = ""): string
{
    $string = strtolower((string) $string);
    $unwanted_array = array(
        'Š' => 'S',
        'š' => 's',
        'Ž' => 'Z',
        'ž' => 'z',
        'À' => 'A',
        'Á' => 'A',
        'Â' => 'A',
        'Ã' => 'A',
        'Ä' => 'A',
        'Å' => 'A',
        'Æ' => 'A',
        'Ç' => 'C',
        'È' => 'E',
        'É' => 'E',
        'Ê' => 'E',
        'Ë' => 'E',
        'Ì' => 'I',
        'Í' => 'I',
        'Î' => 'I',
        'Ï' => 'I',
        'Ñ' => 'N',
        'Ò' => 'O',
        'Ó' => 'O',
        'Ô' => 'O',
        'Õ' => 'O',
        'Ö' => 'O',
        'Ø' => 'O',
        'Ù' => 'U',
        'Ú' => 'U',
        'Û' => 'U',
        'Ü' => 'U',
        'Ý' => 'Y',
        'Þ' => 'B',
        'ß' => 'Ss',
        'à' => 'a',
        'á' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'ä' => 'a',
        'å' => 'a',
        'æ' => 'a',
        'ç' => 'c',
        'è' => 'e',
        'é' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'ì' => 'i',
        'í' => 'i',
        'î' => 'i',
        'ï' => 'i',
        'ð' => 'o',
        'ñ' => 'n',
        'ò' => 'o',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ö' => 'o',
        'ø' => 'o',
        'ù' => 'u',
        'ú' => 'u',
        'û' => 'u',
        'ý' => 'y',
        'þ' => 'b',
        'ÿ' => 'y',
        'Ã«' => 'e'
    );
    $string = strtr($string, $unwanted_array);
    $string = preg_replace("/[^a-z0-9_\s-]/", "", $string);
    $string = preg_replace("/[\s-]+/", " ", $string);
    $string = preg_replace("/[\s_]/", "-", $string);
    return $string;
} //end function
