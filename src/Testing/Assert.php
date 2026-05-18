<?php

declare(strict_types=1);

namespace Cloude\Testing;

/**
 * Static assertion library — the inner core of `Cloude\Testing\TestCase`.
 * Method names mirror PHPUnit's so migrating tests is mechanical:
 * `$this->assertSame(...)` → no change.
 *
 * Failures throw {@see AssertionFailedException}; the {@see Runner}
 * catches it and reports the test as a failure (vs. errors caused by
 * unexpected exceptions / fatal errors).
 *
 * Methods are static so consumer code can call them as
 * `\Cloude\Testing\Assert::same($expected, $actual)` from helpers /
 * higher-level assertion builders. `TestCase` re-exposes them as
 * `$this->assertX(...)` / `self::assertX(...)` for PHPUnit-style use.
 */
final class Assert
{
    /** Running counter — increments on every successful assertion. */
    private static int $count = 0;

    public static function assertionCount(): int
    {
        return self::$count;
    }

    public static function resetCount(): void
    {
        self::$count = 0;
    }

    // ── equality ──────────────────────────────────────────────────────────

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            self::fail(self::join(
                $message,
                'Failed asserting that ' . self::dump($actual) . ' is identical to ' . self::dump($expected),
            ));
        }
        self::$count++;
    }

    public static function notSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            self::fail(self::join(
                $message,
                'Failed asserting that two variables are not identical (both ' . self::dump($actual) . ')',
            ));
        }
        self::$count++;
    }

    public static function equals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {            // intentional loose ==
            self::fail(self::join(
                $message,
                'Failed asserting that ' . self::dump($actual) . ' equals ' . self::dump($expected),
            ));
        }
        self::$count++;
    }

    // ── booleans / nulls ──────────────────────────────────────────────────

    public static function true(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' is true'));
        }
        self::$count++;
    }

    public static function false(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' is false'));
        }
        self::$count++;
    }

    public static function notFalse(mixed $value, string $message = ''): void
    {
        if ($value === false) {
            self::fail(self::join($message, 'Failed asserting that value is not false'));
        }
        self::$count++;
    }

    public static function null(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' is null'));
        }
        self::$count++;
    }

    public static function notNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            self::fail(self::join($message, 'Failed asserting that value is not null'));
        }
        self::$count++;
    }

    // ── counts / emptiness ────────────────────────────────────────────────

    /**
     * @param \Countable|array<mixed>|iterable<mixed> $countable
     */
    public static function count(int $expected, mixed $countable, string $message = ''): void
    {
        $actual = is_array($countable) || $countable instanceof \Countable
            ? count($countable)
            : iterator_count($countable);
        if ($actual !== $expected) {
            self::fail(self::join($message, "Failed asserting that actual size $actual matches expected size $expected"));
        }
        self::$count++;
    }

    public static function empty(mixed $value, string $message = ''): void
    {
        if (!empty($value) === true) {
            // weird: hit when empty() is false → value is non-empty
        }
        if (!empty($value)) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' is empty'));
        }
        self::$count++;
    }

    public static function notEmpty(mixed $value, string $message = ''): void
    {
        if (empty($value)) {
            self::fail(self::join($message, 'Failed asserting that value is not empty'));
        }
        self::$count++;
    }

    // ── instanceof ────────────────────────────────────────────────────────

    public static function instanceOf(string $class, mixed $value, string $message = ''): void
    {
        if (!($value instanceof $class)) {
            $got = is_object($value) ? $value::class : gettype($value);
            self::fail(self::join($message, "Failed asserting that $got is an instance of $class"));
        }
        self::$count++;
    }

    public static function notInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        if ($value instanceof $class) {
            self::fail(self::join($message, "Failed asserting that value is not an instance of $class"));
        }
        self::$count++;
    }

    // ── strings ───────────────────────────────────────────────────────────

    public static function stringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            self::fail(self::join($message, "Failed asserting that string contains \"$needle\"\n  in: " . self::trunc($haystack)));
        }
        self::$count++;
    }

    public static function stringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            self::fail(self::join($message, "Failed asserting that string does NOT contain \"$needle\""));
        }
        self::$count++;
    }

    public static function stringStartsWith(string $prefix, string $value, string $message = ''): void
    {
        if (!str_starts_with($value, $prefix)) {
            self::fail(self::join($message, 'Failed asserting that ' . self::trunc($value) . " starts with \"$prefix\""));
        }
        self::$count++;
    }

    public static function stringEndsWith(string $suffix, string $value, string $message = ''): void
    {
        if (!str_ends_with($value, $suffix)) {
            self::fail(self::join($message, 'Failed asserting that ' . self::trunc($value) . " ends with \"$suffix\""));
        }
        self::$count++;
    }

    public static function matchesRegex(string $pattern, string $value, string $message = ''): void
    {
        if (preg_match($pattern, $value) !== 1) {
            self::fail(self::join($message, 'Failed asserting that ' . self::trunc($value) . " matches $pattern"));
        }
        self::$count++;
    }

    public static function isString(mixed $value, string $message = ''): void
    {
        if (!is_string($value)) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' is a string'));
        }
        self::$count++;
    }

    public static function json(string $value, string $message = ''): void
    {
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::fail(self::join($message, 'Failed asserting that string is valid JSON: ' . json_last_error_msg()));
        }
        self::$count++;
    }

    // ── arrays / iterables ────────────────────────────────────────────────

    /**
     * @param array<mixed>|iterable<mixed> $haystack
     */
    public static function contains(mixed $needle, mixed $haystack, string $message = ''): void
    {
        $found = false;
        foreach ($haystack as $item) {
            if ($item === $needle) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            self::fail(self::join($message, 'Failed asserting that iterable contains ' . self::dump($needle)));
        }
        self::$count++;
    }

    /**
     * @param array<mixed> $array
     */
    public static function arrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            self::fail(self::join($message, "Failed asserting that array has key \"$key\""));
        }
        self::$count++;
    }

    /**
     * @param array<mixed> $array
     */
    public static function arrayNotHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (array_key_exists($key, $array)) {
            self::fail(self::join($message, "Failed asserting that array does NOT have key \"$key\""));
        }
        self::$count++;
    }

    // ── comparisons ───────────────────────────────────────────────────────

    public static function greaterThan(mixed $threshold, mixed $value, string $message = ''): void
    {
        if (!($value > $threshold)) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' > ' . self::dump($threshold)));
        }
        self::$count++;
    }

    public static function lessThan(mixed $threshold, mixed $value, string $message = ''): void
    {
        if (!($value < $threshold)) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' < ' . self::dump($threshold)));
        }
        self::$count++;
    }

    public static function lessThanOrEqual(mixed $threshold, mixed $value, string $message = ''): void
    {
        if (!($value <= $threshold)) {
            self::fail(self::join($message, 'Failed asserting that ' . self::dump($value) . ' <= ' . self::dump($threshold)));
        }
        self::$count++;
    }

    // ── filesystem ────────────────────────────────────────────────────────

    public static function fileExists(string $path, string $message = ''): void
    {
        if (!is_file($path)) {
            self::fail(self::join($message, "Failed asserting that file '$path' exists"));
        }
        self::$count++;
    }

    public static function directoryExists(string $path, string $message = ''): void
    {
        if (!is_dir($path)) {
            self::fail(self::join($message, "Failed asserting that directory '$path' exists"));
        }
        self::$count++;
    }

    // ── escape hatch ──────────────────────────────────────────────────────

    /**
     * Unconditional failure. Useful for asserting that a branch wasn't
     * reached (`self::fail('expected exception')`).
     */
    public static function fail(string $message): never
    {
        throw new AssertionFailedException($message === '' ? 'Assertion failed' : $message);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private static function join(string $userMessage, string $detail): string
    {
        return $userMessage === '' ? $detail : ($userMessage . "\n" . $detail);
    }

    /** Compact var rendering for failure messages. */
    private static function dump(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . self::trunc($value, 60) . "'",
            is_array($value) => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            default => gettype($value),
        };
    }

    private static function trunc(string $s, int $max = 200): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '… [' . strlen($s) . ' chars]';
    }
}
