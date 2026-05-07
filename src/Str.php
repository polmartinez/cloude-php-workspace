<?php

declare(strict_types=1);

namespace Cloude;

class Str
{
    /**
     * Returns the text up to (but not including) the first occurrence of $char.
     * If $char is not found, returns the full string.
     */
    public static function upTo(string $text, string $char): string
    {
        $pos = mb_strpos($text, $char);
        return $pos !== false ? mb_substr($text, 0, $pos) : $text;
    }

    /**
     * Truncates the text to $length characters, appending $ellipsis when cut.
     */
    public static function truncate(string $text, int $length, string $ellipsis = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . $ellipsis;
    }

    /**
     * Converts text into a URL-safe slug.
     *
     * Transliteration order of preference:
     *   1. ext-intl Transliterator (best Unicode coverage, handles Cyrillic,
     *      Greek, CJK romanisation, etc.)
     *   2. iconv ASCII//TRANSLIT//IGNORE (handles common Latin diacritics)
     *   3. raw lowercase + regex strip (last resort)
     */
    public static function slug(string $text, int $maxLength = 128, string $separator = '-'): string
    {
        $text = trim($text);

        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if ($tr !== null) {
                $latin = $tr->transliterate($text);
                if ($latin !== false) {
                    $text = $latin;
                }
            }
        } else {
            $text = mb_strtolower($text, 'UTF-8');
            if (function_exists('iconv')) {
                $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
                if ($translit !== false) {
                    $text = $translit;
                }
            }
        }

        $text = (string) preg_replace('/[^a-z0-9]+/i', $separator, $text);
        $text = trim($text, $separator);
        if ($maxLength > 0 && strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength);
            $text = rtrim($text, $separator);
        }
        return strtolower($text);
    }
}
