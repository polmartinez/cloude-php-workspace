<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Array helpers with dot-notation access.
 *
 * The handful of methods you actually reach for when working with deeply
 * nested PHP arrays — typed access, safe defaults, flatten / inflate,
 * recursive merge, and column extraction.
 *
 *   Arr::get($cfg, 'db.read.host', 'localhost');
 *   Arr::set($cfg, 'db.read.port', 5432);
 *   Arr::has($cfg, 'db.read');
 *   Arr::pluck($users, 'name', 'id');           // ['1' => 'Ana', ...]
 *   Arr::only($row, ['id', 'name']);
 *   Arr::dot(['a' => ['b' => 1]]);              // ['a.b' => 1]
 *   Arr::undot(['a.b' => 1]);                   // ['a' => ['b' => 1]]
 *   Arr::merge(['a' => 1], ['a' => 2]);         // ['a' => 2]
 *
 * Convention: associative arrays are walked recursively, list arrays
 * (numeric keys 0..n) are treated as leaves — matching how JSON decodes
 * objects vs arrays. Use plain PHP for list-specific operations.
 */
class Arr
{
    /**
     * Reads a value at $key (which may be a dot-path like "a.b.c").
     * Returns $default when any segment along the path is missing or
     * not traversable.
     *
     * @param array<mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }
        if (!str_contains($key, '.')) {
            return array_key_exists($key, $array) ? $array[$key] : $default;
        }
        $current = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /**
     * Writes $value at $key (dot-path supported). Intermediate arrays
     * are created as needed; existing scalar segments are overwritten
     * with arrays.
     *
     * @param array<mixed> $array
     */
    public static function set(array &$array, string $key, mixed $value): void
    {
        if ($key === '') {
            return;
        }
        if (!str_contains($key, '.')) {
            $array[$key] = $value;
            return;
        }
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $current = &$array;
        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }
        $current[$last] = $value;
    }

    /**
     * True when every segment along $key resolves to an existing key.
     * Note that a value of null still counts as "present", just like
     * `array_key_exists` (unlike `isset`).
     *
     * @param array<mixed> $array
     */
    public static function has(array $array, string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (!str_contains($key, '.')) {
            return array_key_exists($key, $array);
        }
        $current = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }
        return true;
    }

    /**
     * Removes the value at $key (dot-path supported). Silently no-ops
     * when the path doesn't exist.
     *
     * @param array<mixed> $array
     */
    public static function forget(array &$array, string $key): void
    {
        if ($key === '') {
            return;
        }
        if (!str_contains($key, '.')) {
            unset($array[$key]);
            return;
        }
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $current = &$array;
        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }
            $current = &$current[$segment];
        }
        unset($current[$last]);
    }

    /**
     * Extracts a column from a list of rows. Optionally rekeys the result
     * by another column. $column and $key support dot-path.
     *
     *   Arr::pluck($users, 'name');             // [0 => 'Ana', 1 => 'Bea', ...]
     *   Arr::pluck($users, 'name', 'id');       // [42 => 'Ana', 51 => 'Bea']
     *   Arr::pluck($events, 'meta.title');      // dot-path supported
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function pluck(array $array, string $column, ?string $key = null): array
    {
        $result = [];
        foreach ($array as $rowKey => $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = self::get($row, $column);
            if ($key !== null) {
                $newKey = self::get($row, $key) ?? $rowKey;
                $result[$newKey] = $value;
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }

    /**
     * Returns a subset of $array keeping only the listed top-level keys.
     *
     * @param array<mixed>     $array
     * @param array<int,string> $keys
     * @return array<mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Returns $array with the listed top-level keys removed.
     *
     * @param array<mixed>     $array
     * @param array<int,string> $keys
     * @return array<mixed>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Flattens a nested array into a single level using dot-notation keys.
     * Numeric / list arrays are treated as leaves and kept as-is.
     *
     *   Arr::dot(['a' => ['b' => 1, 'c' => 2]])
     *   // => ['a.b' => 1, 'a.c' => 2]
     *
     * @param array<mixed> $array
     * @return array<string,mixed>
     */
    public static function dot(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $result += self::dot($value, $path);
            } else {
                $result[$path] = $value;
            }
        }
        return $result;
    }

    /**
     * Inverse of `dot()`: builds a nested array from a flat dot-notation map.
     *
     * @param array<string,mixed> $array
     * @return array<mixed>
     */
    public static function undot(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            self::set($result, (string) $key, $value);
        }
        return $result;
    }

    /**
     * Recursively merges associative arrays. Later values override earlier
     * ones; nested associative arrays are merged in turn. List arrays are
     * replaced wholesale (not concatenated) — simpler and matches the
     * mental model used in JSON / config merging.
     *
     * @param array<mixed> ...$arrays
     * @return array<mixed>
     */
    public static function merge(array ...$arrays): array
    {
        $result = array_shift($arrays) ?? [];
        foreach ($arrays as $next) {
            foreach ($next as $key => $value) {
                if (
                    is_array($value)
                    && isset($result[$key])
                    && is_array($result[$key])
                    && !array_is_list($value)
                    && !array_is_list($result[$key])
                ) {
                    $result[$key] = self::merge($result[$key], $value);
                } else {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }
}
