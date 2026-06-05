<?php

declare(strict_types=1);

namespace Cloude;

class Str
{
    /**
     * Multibyte-safe substring — thin wrapper over `mb_substr()`.
     *
     *   Str::sub('Cataluña', 0, 4);    // → 'Cata'
     *   Str::sub('Cataluña', -3);       // → 'uña'  (last 3 chars)
     *   Str::sub('Cataluña', 4, 3);    // → 'luñ'
     *
     * Always counts codepoints, not bytes — `mb_strlen('ñ') === 1`
     * whereas `strlen('ñ') === 2`. Prefer this over the byte-level
     * `substr()` in any code that may see non-ASCII text.
     */
    public static function sub(string $text, int $start, ?int $length = null): string
    {
        return mb_substr($text, $start, $length);
    }

    /**
     * Multibyte-safe string length — thin wrapper over `mb_strlen()`.
     *
     *   Str::len('Cataluña');          // → 8
     *   Str::len('hello');              // → 5
     *
     * Same rationale as `sub()`: counts codepoints, not bytes. Pair
     * with `sub()` and `truncate()` for predictable multibyte handling.
     */
    public static function len(string $text): int
    {
        return mb_strlen($text);
    }

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
     * Truncates the text to at most `$length` characters, appending
     * `$ellipsis` when cut. **The final length never exceeds `$length`
     * (ellipsis included)** — same contract as `truncateMiddle()`.
     *
     *   Str::truncate('hello world', 8);     // → 'hello...'   (8 chars)
     *   Str::truncate('hello world', 4);     // → 'h...'       (4 chars)
     *   Str::truncate('hello', 10);          // → 'hello'      (unchanged, fits)
     *
     * Edge case: when the ellipsis itself is `>= $length`, returns a
     * clipped ellipsis (consistent with `truncateMiddle`). Multibyte-safe.
     */
    public static function truncate(string $text, int $length, string $ellipsis = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $ellipsisLen = mb_strlen($ellipsis);
        if ($ellipsisLen >= $length) {
            return mb_substr($ellipsis, 0, $length);
        }
        return mb_substr($text, 0, $length - $ellipsisLen) . $ellipsis;
    }

    /**
     * Truncates from the middle, keeping equal-ish chunks of the start
     * and end joined by `$ellipsis`. The final length never exceeds
     * `$maxLength` (ellipsis included).
     *
     *   Str::truncateMiddle('Cloude framework for PHP 8.4', 16);
     *   // → 'Cloude f... PHP 8.4'  → 16 chars: 7 + 3 + 6
     *
     *   Str::truncateMiddle('/var/log/app/very/deep/path/to/file.log', 25);
     *   // → '/var/log/app...to/file.log'
     *
     * Useful for paths, hashes, breadcrumbs and any "I need to see
     * both ends but not the middle" rendering. Multibyte-safe.
     *
     * The end gets the extra character when `$maxLength - strlen($ellipsis)`
     * is odd (so filenames / suffixes keep maximum context).
     */
    public static function truncateMiddle(string $text, int $maxLength, string $ellipsis = '...'): string
    {
        $len = mb_strlen($text);
        if ($len <= $maxLength) {
            return $text;
        }
        $ellipsisLen = mb_strlen($ellipsis);

        // Edge: ellipsis itself is too long — fall back to truncating
        // the ellipsis (the only sensible option in this constrained
        // case; both sides of the source would be 0-length anyway).
        if ($ellipsisLen >= $maxLength) {
            return mb_substr($ellipsis, 0, $maxLength);
        }

        $available = $maxLength - $ellipsisLen;
        $startLen  = (int) floor($available / 2);
        $endLen    = (int) ceil($available / 2);

        return mb_substr($text, 0, $startLen) . $ellipsis . mb_substr($text, -$endLen);
    }

    /**
     * Transliterates a string to ASCII, preserving separators and punctuation.
     * "Análisis Político" → "Analisis Politico"; "Москва" → "Moskva".
     *
     * Use this for fuzzy matching, search indexing, etc. For URL slugs use
     * `slug()` instead, which also lowercases and strips punctuation.
     *
     * Uses ext-intl Transliterator when available — that's the only path
     * with a real character mapping. When ext-intl is missing, falls back
     * to a single regex that drops every non-ASCII byte; it doesn't
     * "translate" anything (no built-in tables), so "Cataluña" becomes
     * "Catalua" instead of "Cataluna". Install ext-intl for proper output.
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
        return (string) preg_replace('/[^\x00-\x7f]+/u', '', $text);
    }

    /**
     * Converts text into a URL-safe slug.
     *
     * When ext-intl is installed, `Transliterator` handles diacritics and
     * non-Latin scripts (Cyrillic, Greek, CJK romanisation, …) so
     * "Cataluña" → "cataluna" and "Москва" → "moskva".
     *
     * Without ext-intl there is no transliteration step (no built-in
     * tables) — the trailing regex simply collapses every non-`[a-z0-9]`
     * run into the separator, which means "Cataluña" → "catalu-a" and
     * "Москва" → "" (everything dropped). Install ext-intl for sites that
     * need proper non-ASCII slugs.
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
