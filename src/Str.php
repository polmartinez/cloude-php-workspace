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
     * Transliterates a string to ASCII, preserving separators and punctuation.
     * "Análisis Político" → "Analisis Politico"; "Москва" → "Moskva".
     *
     * Use this for fuzzy matching, search indexing, etc. For URL slugs use
     * `slug()` instead, which also lowercases and strips punctuation.
     *
     * Uses ext-intl Transliterator when available, otherwise falls back to
     * iconv ASCII//TRANSLIT//IGNORE, otherwise returns the input unchanged.
     */
    public static function ascii(string $text): string
    {
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr !== null) {
                $r = $tr->transliterate($text);
                if ($r !== false) {
                    return $r;
                }
            }
        }
        if (function_exists('iconv')) {
            $r = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($r !== false) {
                return $r;
            }
        }
        return $text;
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

    /**
     * Returns the substring after the first occurrence of $needle.
     * If $needle is not found, returns the full string. Empty $needle
     * also returns the full string.
     */
    public static function after(string $haystack, string $needle): string
    {
        if ($needle === '') {
            return $haystack;
        }
        $pos = mb_strpos($haystack, $needle);
        return $pos === false ? $haystack : mb_substr($haystack, $pos + mb_strlen($needle));
    }

    /**
     * Returns the substring after the last occurrence of $needle.
     * If $needle is not found, returns the full string.
     */
    public static function afterLast(string $haystack, string $needle): string
    {
        if ($needle === '') {
            return $haystack;
        }
        $pos = mb_strrpos($haystack, $needle);
        return $pos === false ? $haystack : mb_substr($haystack, $pos + mb_strlen($needle));
    }

    /**
     * Returns the substring between $from and $to (excluding the delimiters).
     * Returns '' when either delimiter is not found or they are out of order.
     *
     *   Str::between('foo [bar] baz', '[', ']');   // 'bar'
     */
    public static function between(string $haystack, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return '';
        }
        $start = mb_strpos($haystack, $from);
        if ($start === false) {
            return '';
        }
        $start += mb_strlen($from);
        $end = mb_strpos($haystack, $to, $start);
        if ($end === false) {
            return '';
        }
        return mb_substr($haystack, $start, $end - $start);
    }

    /**
     * Collapses any run of whitespace (incl. \n, \t) into a single space and
     * trims the result. Useful before logging or hashing free-form text.
     */
    public static function squish(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Truncates to N words rather than characters. Word boundary is /\s+/.
     */
    public static function words(string $text, int $count, string $end = '...'): string
    {
        if ($count <= 0) {
            return '';
        }
        $words = preg_split('/(\s+)/u', $text, $count + 1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        if (count($words) <= $count * 2 - 1) {
            return $text;
        }
        return rtrim(implode('', array_slice($words, 0, $count * 2 - 1))) . $end;
    }

    /**
     * Cryptographically-strong random token, base64-url encoded (no padding).
     * 16 bytes (default) → 22 chars; 32 bytes → 43 chars.
     */
    public static function random(int $bytes = 16): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(1, $bytes))), '+/', '-_'), '=');
    }

    /**
     * RFC 4122 UUID v4 (random). Lowercase, with hyphens.
     */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant 10x
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Hash shortcut. Defaults to sha256 (hex). Pass a different algo from
     * `hash_algos()` if you need it (md5, sha1, sha512, ...).
     */
    public static function hash(string $text, string $algo = 'sha256'): string
    {
        return hash($algo, $text);
    }

    /**
     * Masks the middle of a string for privacy-friendly display. Keeps the
     * first $start characters and (optionally) the last few visible.
     *
     *   Str::mask('pedro@example.com', '*', 2);          // 'pe***************'
     *   Str::mask('+34 600 123 456',   '*', 4, -3);      // '+34 ********456'
     *
     * @param int      $start  Visible prefix length
     * @param int|null $length Length of the masked region; null = until $end
     */
    public static function mask(string $text, string $char = '*', int $start = 0, ?int $length = null): string
    {
        if ($char === '') {
            return $text;
        }
        $total = mb_strlen($text);
        $start = max(0, min($start, $total));
        if ($length === null) {
            $maskLen = $total - $start;
            $tail = '';
        } elseif ($length < 0) {
            $maskLen = max(0, $total - $start + $length);
            $tail = mb_substr($text, $start + $maskLen);
        } else {
            $maskLen = max(0, min($length, $total - $start));
            $tail = mb_substr($text, $start + $maskLen);
        }
        return mb_substr($text, 0, $start) . str_repeat($char, $maskLen) . $tail;
    }

    /**
     * Splits the input on non-alphanumerics + camel-case word boundaries.
     * Internal helper used by camel/snake/kebab/pascal — exposed for tests.
     *
     * @return array<int,string>
     */
    private static function splitWords(string $text): array
    {
        $text = self::ascii(trim($text));
        // Insert space before each upper-case letter following a lower one
        // (camelCase boundary), then split on non-alphanumerics.
        $text = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text);
        $words = preg_split('/[^a-zA-Z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map('strtolower', $words);
    }

    /**
     * camelCase. "user_id" / "user-id" / "User Id" → "userId".
     */
    public static function camel(string $text): string
    {
        $words = self::splitWords($text);
        if ($words === []) {
            return '';
        }
        $first = array_shift($words);
        return $first . implode('', array_map('ucfirst', $words));
    }

    /**
     * PascalCase. "user_id" / "userId" → "UserId".
     */
    public static function pascal(string $text): string
    {
        return implode('', array_map('ucfirst', self::splitWords($text)));
    }

    /**
     * snake_case. "userId" / "User-Id" → "user_id".
     */
    public static function snake(string $text): string
    {
        return implode('_', self::splitWords($text));
    }

    /**
     * kebab-case. "userId" / "User Id" → "user-id".
     */
    public static function kebab(string $text): string
    {
        return implode('-', self::splitWords($text));
    }
}
