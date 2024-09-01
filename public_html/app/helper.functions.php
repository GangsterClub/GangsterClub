<?php

/** TranslationService **/
function __(string $key, array $replacements = []): string
{
    global $app;
    static $translationService = null;

    if ($translationService === null)
    {
        $translationService = $app->get('translationService');
    }

    return $translationService->get($key, $replacements);
}

function translate(string $key, array $replacements = []): string
{
    return __($key, $replacements);
}
