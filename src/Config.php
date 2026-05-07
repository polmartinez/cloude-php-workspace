<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Config bootstrap helpers.
 *
 * Designed to be called from a project's app/config.php to define BASE_URL,
 * DEBUG and similar globals. Reads values from $_ENV / $_SERVER / getenv()
 * and falls back to sensible defaults.
 *
 * Typical usage:
 *
 *   require_once __DIR__ . '/config.local.php';
 *
 *   \Cloude\Config::defineBaseUrl([
 *       'www.example.com',
 *       'example.com',
 *       'localhost',
 *   ]);
 *   \Cloude\Config::defineDebug();
 */
class Config
{
    /**
     * Reads an env var. Empty string is treated as missing.
     */
    public static function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    /**
     * Reads a boolean env var. Truthy strings: 1, true, yes, on (case-insensitive).
     */
    public static function boolEnv(string $key, bool $default = false): bool
    {
        $value = strtolower((string) self::env($key, $default ? 'true' : 'false'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Defines BASE_URL using scheme + Host header, validated against an
     * allowlist of hostnames (matched without port). Hosts not in the list
     * fall back to 'localhost' to prevent host-header injection.
     *
     * If the BASE_URL env var is set, it overrides the auto-detection.
     *
     * @param array<int,string> $allowedHosts Hostnames (no port) accepted as-is
     */
    public static function defineBaseUrl(array $allowedHosts, string $envKey = 'BASE_URL'): void
    {
        if (defined('BASE_URL')) {
            return;
        }
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hostname = explode(':', $host, 2)[0];
        if (!in_array($hostname, $allowedHosts, true)) {
            $host = 'localhost';
        }
        define('BASE_URL', rtrim((string) self::env($envKey, $scheme . '://' . $host), '/'));
    }

    /**
     * Defines the DEBUG constant from a boolean env var.
     */
    public static function defineDebug(string $envKey = 'DEBUG', bool $default = false): void
    {
        if (defined('DEBUG')) {
            return;
        }
        define('DEBUG', self::boolEnv($envKey, $default));
    }
}
