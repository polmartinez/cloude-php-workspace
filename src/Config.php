<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Config bootstrap helpers + environment-aware file loader.
 *
 * Two responsibilities living in one place:
 *
 * 1. **Env-var helpers + global constants** — the original API:
 *    `env()`, `boolEnv()`, `defineBaseUrl()`, `defineDebug()`.
 *
 * 2. **Multi-environment file loader** — added in v0.19. Configs live
 *    under a single directory, with optional per-environment overrides:
 *
 *      app/config/
 *      ├── app.php          # base config (always loaded)
 *      ├── db.php
 *      ├── dev/             # active when environment is 'dev'
 *      │   ├── app.php      # overrides merged into base
 *      │   └── db.php
 *      └── prod/
 *          ├── app.php
 *          └── db.php
 *
 *    Each file `return`s a flat or nested associative array. Base + env
 *    overrides are deep-merged via `Cloude\Arr::merge`.
 *
 *    Bootstrap:
 *
 *      \Cloude\Config::configure(
 *          configPath: dirname(__DIR__) . '/app/config',
 *          environment: getenv('APP_ENV') ?: 'dev',
 *      );
 *
 *    Access:
 *
 *      $cacheTtl = \Cloude\Config::get('app.cache.ttl');   // dot-path
 *      $db       = \Cloude\Config::get('db.default');      // sub-tree
 *      $loaded   = \Cloude\Config::load('db');             // whole file's merged array
 *
 * Environment names are free-form strings. "dev" and "prod" are
 * conventional starting points; nothing stops you adding `staging`,
 * `test`, `ci`, `local`, or anything else.
 *
 * ### Recommended `app/config/app.php`
 *
 *   return [
 *       'base_url' => Cloude\Config::env('BASE_URL'),   // null → auto-detect
 *       'debug'    => Cloude\Config::boolEnv('DEBUG'),
 *       'paths'    => [
 *           'data'  => BASEPATH . '/data',
 *           'views' => APPPATH . '/views',
 *       ],
 *   ];
 *
 * Then read those values via the typed helpers:
 *
 *   Config::baseUrl(['example.com', 'localhost']);  // memoized, validated
 *   Config::debug();                                // bool
 *   Config::path('data');                           // app.paths.data
 */
class Config
{
    private static string $environment = 'dev';
    private static ?string $configPath = null;

    /** @var array<string, array<mixed>> */
    private static array $loaded = [];


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

    // ── multi-environment file loader ─────────────────────────────────────

    /**
     * One-shot setup. Equivalent to `setConfigPath()` + `setEnvironment()`.
     * If `$environment` is null, auto-detect from `APP_ENV` /
     * `ENVIRONMENT` env vars (falling back to the current value, or 'dev').
     */
    public static function configure(string $configPath, ?string $environment = null): void
    {
        self::setConfigPath($configPath);
        if ($environment !== null) {
            self::setEnvironment($environment);
        } else {
            $detected = self::env('APP_ENV') ?? self::env('ENVIRONMENT');
            if ($detected !== null) {
                self::setEnvironment($detected);
            }
        }
    }

    public static function setConfigPath(string $path): void
    {
        self::$configPath = rtrim($path, '/');
        self::$loaded = [];
    }

    public static function setEnvironment(string $environment): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $environment)) {
            throw new \InvalidArgumentException(
                "Environment name '$environment' has invalid chars (allowed: A-Za-z0-9_-)",
            );
        }
        self::$environment = $environment;
        self::$loaded = [];
    }

    public static function environment(): string
    {
        return self::$environment;
    }

    /**
     * Loads `$name.php` from the config directory, merges the per-env
     * override if one exists, and caches the result for the rest of the
     * request. Returns an empty array when neither file is present.
     *
     * @return array<mixed>
     */
    public static function load(string $name): array
    {
        if (isset(self::$loaded[$name])) {
            return self::$loaded[$name];
        }
        if (self::$configPath === null) {
            throw new \RuntimeException(
                'Config path not set; call Config::setConfigPath() or Config::configure() first',
            );
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Config name '$name' has invalid chars (allowed: A-Za-z0-9_-)",
            );
        }

        $base     = self::readFile(self::$configPath . '/' . $name . '.php');
        $override = self::readFile(self::$configPath . '/' . self::$environment . '/' . $name . '.php');

        $merged = $base === [] ? $override : Arr::merge($base, $override);
        return self::$loaded[$name] = $merged;
    }

    /**
     * Convenience accessor: first segment is the config-file name,
     * remaining segments walk the array with `Arr::get`.
     *
     *   Config::get('db.default.dsn')
     *      → Arr::get(Config::load('db'), 'default.dsn')
     */
    public static function get(string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $default;
        }
        [$name, $rest] = self::splitPath($path);
        $loaded = self::load($name);
        if ($rest === '') {
            return $loaded === [] ? $default : $loaded;
        }
        return Arr::get($loaded, $rest, $default);
    }

    /**
     * Drops every cached config — handy in tests / long-running scripts
     * that need to re-read updated files.
     */
    public static function reset(): void
    {
        self::$loaded = [];
        self::$baseUrl = null;
    }

    // ── typed accessors (recommended over scattered global constants) ──

    private static ?string $baseUrl = null;

    /**
     * Returns the project base URL. Resolution order on first call:
     *
     *   1. `app.base_url` config value (if set and non-empty)
     *   2. `BASE_URL` env var (if set)
     *   3. scheme + Host header, validated against $allowedHosts
     *      (any host not in the list collapses to "localhost", preventing
     *      Host-header injection)
     *
     * Memoized for the rest of the request; pass $allowedHosts only on
     * the first call (it's ignored afterwards).
     *
     * If the legacy `BASE_URL` constant is defined, this method returns
     * it untouched — keeps `defineBaseUrl()`-style bootstraps working.
     *
     * @param array<int, string> $allowedHosts Hostnames (no port) accepted as-is
     */
    public static function baseUrl(array $allowedHosts = []): string
    {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }
        if (defined('BASE_URL')) {
            return self::$baseUrl = (string) constant('BASE_URL');
        }

        // 1. Config file
        if (self::$configPath !== null) {
            $cfg = self::get('app.base_url');
            if (is_string($cfg) && $cfg !== '') {
                return self::$baseUrl = rtrim($cfg, '/');
            }
        }

        // 2. Env var
        $env = self::env('BASE_URL');
        if ($env !== null) {
            return self::$baseUrl = rtrim($env, '/');
        }

        // 3. Auto-detect with allowlist
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hostname = explode(':', $host, 2)[0];
        if ($allowedHosts !== [] && !in_array($hostname, $allowedHosts, true)) {
            $host = 'localhost';
        }
        return self::$baseUrl = rtrim($scheme . '://' . $host, '/');
    }

    /**
     * Returns the debug flag. Resolution order:
     *
     *   1. `DEBUG` global constant (legacy back-compat)
     *   2. `app.debug` config value (cast via filter_var BOOLEAN)
     *   3. `DEBUG` env var (boolEnv parsing)
     *   4. false
     */
    public static function debug(): bool
    {
        if (defined('DEBUG')) {
            return (bool) constant('DEBUG');
        }
        if (self::$configPath !== null) {
            $cfg = self::get('app.debug');
            if ($cfg !== null) {
                return filter_var($cfg, FILTER_VALIDATE_BOOLEAN);
            }
        }
        return self::boolEnv('DEBUG', false);
    }

    /**
     * Looks up `app.paths.{name}` and returns it. Falls back to $default
     * when missing. Trailing slashes are not normalised — store the path
     * exactly as you want it returned.
     *
     * Typical app.php config:
     *
     *   'paths' => [
     *       'data'    => BASEPATH . '/data',
     *       'views'   => APPPATH . '/views',
     *       'storage' => BASEPATH . '/storage',
     *   ]
     *
     * Then anywhere in the app: `Config::path('data')`.
     */
    public static function path(string $name, ?string $default = null): ?string
    {
        if (self::$configPath !== null) {
            $value = self::get('app.paths.' . $name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    /**
     * @return array{0:string, 1:string}
     */
    private static function splitPath(string $path): array
    {
        $dot = strpos($path, '.');
        if ($dot === false) {
            return [$path, ''];
        }
        return [substr($path, 0, $dot), substr($path, $dot + 1)];
    }

    /**
     * @return array<mixed>
     */
    private static function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        /** @psalm-suppress UnresolvableInclude */
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException(
                "Config file '$path' must return an array (got " . gettype($data) . ')',
            );
        }
        return $data;
    }
}
