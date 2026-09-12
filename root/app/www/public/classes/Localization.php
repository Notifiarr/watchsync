<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

class Localization
{
    private string $locale    = DEFAULT_LOCALE;
    private ?array $strings   = null;
    private array  $fileCache = [];

    public function __construct()
    {
        $this->locale = $this->resolveLocale();
    }

    public function resolveLocale(): string
    {
        if (isset($_SESSION['locale']) && $this->isValidLocale($_SESSION['locale'])) {
            return $_SESSION['locale'];
        }

        return DEFAULT_LOCALE;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getSupportedLocales(): array
    {
        global $LOCALE_REGISTRY;

        return is_array($LOCALE_REGISTRY) ? $LOCALE_REGISTRY : [];
    }

    public function isValidLocale(string $locale): bool
    {
        $locale = trim($locale);
        if ($locale == '' || !isset($this->getSupportedLocales()[$locale])) {
            return false;
        }

        return is_readable(LOCALIZATION_DIR . $locale . '.json');
    }

    public function loadTranslations(?string $locale = null): array
    {
        $locale   = $locale ?? $this->locale;
        $fallback = $this->readLocaleFile(DEFAULT_LOCALE);
        $primary  = $locale == DEFAULT_LOCALE ? [] : $this->readLocaleFile($locale);

        return array_merge($fallback, $primary);
    }

    public function translate(string $key, array $args = []): string
    {
        if ($this->strings == null) {
            $this->strings = $this->loadTranslations($this->locale);
        }

        $text = $this->strings[$key] ?? $this->readLocaleFile(DEFAULT_LOCALE)[$key] ?? $key;

        foreach ($args as $i => $val) {
            $text = str_replace('{' . $i . '}', strval($val), $text);
        }

        return $text;
    }

    private function readLocaleFile(string $locale): array
    {
        if (isset($this->fileCache[$locale])) {
            return $this->fileCache[$locale];
        }

        $path = LOCALIZATION_DIR . $locale . '.json';
        if (!is_readable($path)) {
            $this->fileCache[$locale] = [];

            return [];
        }

        $raw    = file_get_contents($path);
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            $this->fileCache[$locale] = [];

            return [];
        }

        $out = [];
        foreach ($parsed as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $out[$k] = strval($v);
            }
        }
        $this->fileCache[$locale] = $out;

        return $out;
    }
}
