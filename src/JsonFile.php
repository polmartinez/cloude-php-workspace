<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Disk I/O helper for JSON files. Provides:
 *
 *   - Per-request in-memory cache (multiple reads of the same path → one disk hit).
 *   - Atomic writes (temp file + rename, so partial writes never appear on disk).
 *   - Sensible default flags: UNESCAPED_UNICODE | UNESCAPED_SLASHES.
 *
 * Convention: any failure (missing file, decode error, write error) is reported
 * via return value — `null` / `false` — rather than exceptions. Callers that
 * want strict behaviour should check the result.
 */
class JsonFile
{
    /** @var array<string, array<mixed>|null> */
    private static array $cache = [];

    /**
     * Reads a JSON file. Returns null if the file is missing, unreadable, or
     * does not decode to an array. Result is cached per-request by path.
     *
     * @return array<mixed>|null
     */
    public static function read(string $path): ?array
    {
        if (array_key_exists($path, self::$cache)) {
            return self::$cache[$path];
        }
        if (!is_file($path)) {
            return self::$cache[$path] = null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::$cache[$path] = null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::$cache[$path] = null;
        }
        return self::$cache[$path] = $decoded;
    }

    /**
     * Like read(), but returns $default when the file is missing or invalid.
     *
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public static function readOr(string $path, array $default = []): array
    {
        return self::read($path) ?? $default;
    }

    /**
     * Atomically writes $data as JSON to $path. The data is encoded with
     * UNESCAPED_UNICODE | UNESCAPED_SLASHES; pass $pretty = true for
     * JSON_PRETTY_PRINT.
     *
     * Implementation: write to a temp file in the same directory, then rename
     * it over the destination. rename(2) is atomic on POSIX, so concurrent
     * readers never see a partially-written file.
     *
     * @param array<mixed> $data
     */
    public static function write(string $path, array $data, bool $pretty = false): bool
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($data, $flags);
        if ($json === false) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        $tmp = @tempnam($dir, '.json.');
        if ($tmp === false) {
            return false;
        }
        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        unset(self::$cache[$path]);
        return true;
    }

    public static function exists(string $path): bool
    {
        return is_file($path);
    }

    /**
     * Drops the in-memory cache. Pass a path to drop a single entry; omit it
     * to clear the whole cache (useful in long-running scripts and tests).
     */
    public static function clearCache(?string $path = null): void
    {
        if ($path === null) {
            self::$cache = [];
            return;
        }
        unset(self::$cache[$path]);
    }
}
