<?php

declare(strict_types=1);

namespace app\Service;

use Symfony\Component\Yaml\Yaml;
use app\Service\YamlCacheService as TranslationsCache;

class TranslationService
{
    /**
     * Summary of locale
     * @var string
     */
    protected string $locale = 'en';

    /**
     * Summary of fallbackLocale
     * @var string
     */
    protected string $fallbackLocale = 'en';

    /**
     * Summary of supportedLanguages
     * @var array
     */
    protected array $supportedLanguages = [];

    /**
     * Summary of translations
     * @var array
     */
    protected array $translations = [];

    /**
     * Summary of file
     * @var string
     */
    protected string $file = 'messages';

    /**
     * Summary of __construct
     * @param string $locale
     * @param string $fallbackLocale
     */
    public function __construct(string $locale = 'en', string $fallbackLocale = 'en')
    {
        $this->locale = $locale;
        $this->fallbackLocale = $fallbackLocale;
        $this->supportedLanguages = include_once __DIR__ . '/../languages.php';
    }

    /**
     * Summary of get
     * @param string $key
     * @param array $replacements
     * @param bool $useFallback
     * @return string
     */
    public function get(string $key, array $replacements = [], bool $useFallback = true): string
    {
        $file = $this->file;
        $messageKey = $key;
        if ((bool) strpos($key, '.') === true) {
            [
                $file,
                $messageKey
            ] = explode('.', $key, 2);
        }

        $this->loadTranslationFile($this->locale, $file);
        $translation = ($this->translations[$this->locale][$file][$messageKey] ?? null);
        if ($translation === null && $useFallback === true) {
            $translation = $this->getFallbackTranslation($file, $messageKey);
        }

        return $this->replacePlaceholders(($translation ?? $key), $replacements);
    }

    /**
     * Summary of loadTranslationFile
     * @param string $locale
     * @param string $file
     * @return void
     */
    protected function loadTranslationFile(string $locale, string $file): void
    {
        if (isset($this->translations[$locale][$file]) === true) {
            return;
        }

        $filePath = DOC_ROOT . "/src/resources/lang/{$locale}/{$file}.yaml";
        if (file_exists($filePath) === true) {
            $cachedFilePath = TranslationsCache::getPath($filePath);
            $cachedTranslations = TranslationsCache::loadCache($cachedFilePath);
            if (empty($cachedTranslations) === false && is_array($cachedTranslations) === true) {
                $this->translations[$locale][$file] = $cachedTranslations;
                return;
            }

            $parsedTranslations = $this->parseTranslationFile($filePath);
            $this->translations[$locale][$file] = $parsedTranslations;
            TranslationsCache::storeCache($cachedFilePath, $parsedTranslations);
            return;
        }

        $this->translations[$locale][$file] = [];
    }

    /**
     * Summary of parseTranslationFile make this its own class or YamlCacheService class function, if more parsing is required
     * @param string $filePath
     * @param array $parsed
     * @return array
     */
    private function parseTranslationFile(string $filePath, array $parsed = []): array
    {
        if ((bool) function_exists('yaml_parse_file') === true) {
            $parsedTranslations = @yaml_parse_file($filePath) ?: $parsed;
        }

        if ((bool) class_exists('\Symfony\Component\Yaml\Yaml') === true && isset($parsedTranslations) === false) {
            $parsedTranslations = @Yaml::parseFile($filePath) ?: $parsed;
        }

        return $parsedTranslations ?: $parsed;
    }

    /**
     * Summary of getFallbackTranslation
     * @param string $file
     * @param string $messageKey
     * @throws \Exception
     * @return string
     */
    protected function getFallbackTranslation(string $file, string $messageKey): string
    {
        $this->loadTranslationFile($this->fallbackLocale, $file);
        if (isset($this->translations[$this->fallbackLocale][$file][$messageKey]) === true) {
            return $this->translations[$this->fallbackLocale][$file][$messageKey];
        }

        $errorMessage = sprintf('Missing translation key "%s" for file "%s".', $messageKey, $file);
        throw new \Exception(htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Summary of replacePlaceholders
     * @param string $message
     * @param array $replacements
     * @return string
     */
    protected function replacePlaceholders(string $message, array $replacements): string
    {
        foreach ($replacements as $placeholder => $value) {
            if (is_array($value) === true) {
                $value = implode(
                    ', ',
                    array_map(
                        static fn($item) => (is_scalar($item) || $item === null) ? (string) $item : '[complex]',
                        $value
                    )
                );
            } elseif ($value instanceof \Stringable) {
                $value = (string) $value;
            } elseif (is_scalar($value) === false && $value !== null) {
                $value = '[complex]';
            }

            $message = str_replace(':' . $placeholder, (string) $value, $message);
        }

        return $message;
    }

    /**
     * Summary of setLocale
     * @param string $locale
     * @return void
     */
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

    /**
     * Summary of getLocale
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Summary of setFallbackLocale
     * @param string $fallbackLocale
     * @return void
     */
    public function setFallbackLocale(string $fallbackLocale): void
    {
        $this->fallbackLocale = $fallbackLocale;
    }

    /**
     * Summary of getFallbackLocale
     * @return string
     */
    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /**
     * Summary of getSupportedLanguages
     * @return array
     */
    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }

    /**
     * Summary of setFile
     * @param string $filename
     * @return void
     */
    public function setFile(string $filenameWithoutExtension): void
    {
        $this->file = $filenameWithoutExtension;
    }
}
