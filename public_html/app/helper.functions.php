<?php

/**
 * Summary of __
 * @param string $key
 * @param array $replacements
 * @return string
 */
function __(string $key, array $replacements=[]): string
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
function translate(string $key, array $replacements=[]): string
{
    return __($key, $replacements);
}
