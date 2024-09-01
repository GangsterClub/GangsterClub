<?php

declare(strict_types=1);

namespace app\Business;

use app\Business\YamlCacheService as TranslationsCache;

class TranslationService
{
    protected string $locale = 'en';
    protected string $fallbackLocale = 'en';
    protected array $supportedLanguages = [];
    protected array $translations = [];

    public function __construct(string $locale='en', string $fallbackLocale='en')
    {
        $this->locale = $locale;
        $this->fallbackLocale = $fallbackLocale;
        $this->supportedLanguages = include_once __DIR__.'/../languages.php';
    }

    public function get(string $key, array $replacements=[], bool $useFallback=true): string
    {
        [
            $file,
            $messageKey
        ] = explode('.', $key, 2);
        $this->loadTranslationFile($this->locale, $file);
        $translation = ($this->translations[$this->locale][$file][$messageKey] ?? null);
        if ($translation === null && $useFallback === true) {
            $translation = $this->getFallbackTranslation($file, $messageKey);
        }

        return $this->replacePlaceholders(($translation ?? $key), $replacements);
    }

    protected function loadTranslationFile(string $locale, string $file): void
    {
        if (isset($this->translations[$locale][$file]) === true) {
            return;
        }

        $filePath = __DIR__."/../resources/lang/{$locale}/{$file}.yaml";
        $cachedFilePath = TranslationsCache::getPath($filePath);
        $cachedTranslations = TranslationsCache::loadCache($cachedFilePath);
        if (empty($cachedTranslations) === false && is_array($cachedTranslations) === true) {
            $this->translations[$locale][$file] = $cachedTranslations;
            return;
        }

        if (file_exists($filePath) === true) {
            $parsedTranslations = (@yaml_parse_file($filePath) ?: []);
            $this->translations[$locale][$file] = $parsedTranslations;
            TranslationsCache::storeCache($cachedFilePath, $parsedTranslations);
            return;
        }

        $this->translations[$locale][$file] = [];
    }

    protected function getFallbackTranslation(string $file, string $messageKey): string
    {
        $this->loadTranslationFile($this->fallbackLocale, $file);
        if (isset($this->translations[$this->fallbackLocale][$file][$messageKey]) === true) {
            return $this->translations[$this->fallbackLocale][$file][$messageKey];
        }

        $errorMessage = sprintf(
            'Missing translation key "%s" for file "%s".',
            htmlspecialchars($messageKey, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($file, ENT_QUOTES, 'UTF-8')
        );

        throw new \Exception($errorMessage);
    }

    protected function replacePlaceholders(string $message, array $replacements): string
    {
        foreach ($replacements as $placeholder => $value) {
            $message = str_replace(':'.$placeholder, $value, $message);
        }

        return $message;
    }

    public function setLocale(string $locale): void
    {
        if (array_key_exists($locale, $this->supportedLanguages) === true) {
            $this->locale = $locale;
        }

        if (array_key_exists($this->locale, $this->supportedLanguages) === false) {
            $this->locale = $this->fallbackLocale;
        }

        setlocale(LC_ALL, $this->locale);
        $this->translations[$this->locale] = [];
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setFallbackLocale(string $fallbackLocale): void
    {
        $this->fallbackLocale = $fallbackLocale;
    }

    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }
}
