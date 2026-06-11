<?php

declare(strict_types=1);

namespace Cloude;

/**
 * One-stop entry-point bootstrap.
 *
 * Two conventions live here:
 *
 * ### Path constants (define once, in www/index.php)
 *
 * The framework relies on a small, fixed set of directory constants —
 * everything else lives in Config files. This mirrors FuelPHP's
 * `DOCROOT` / `APPPATH` / `PKGPATH` approach:
 *
 *   - `DOCROOT`   public web root (where `www/index.php` lives)
 *   - `APPPATH`   application root (where `app/` lives)
 *   - `BASEPATH`  project root (parent of `app/`, `www/`, `data/`...)
 *
 * Define them by calling `Bootstrap::initPaths()` before anything else.
 *
 * ### Recommended www/index.php
 *
 *   require __DIR__ . '/../vendor/autoload.php';
 *
 *   \Cloude\Bootstrap::initPaths(
 *       docroot: __DIR__,
 *       apppath: dirname(__DIR__) . '/app',
 *   );
 *   \Cloude\Config::configure(APPPATH . '/config');
 *
 *   if (\Cloude\Bootstrap::serveStaticIfExists(DOCROOT)) {
 *       return false;
 *   }
 *
 *   \Cloude\Bootstrap::run();   // pulls debug / views from Config
 *
 *   $router = new \Cloude\Router(\Cloude\Config::baseUrl(['example.com']));
 *   // ...routes...
 *   $router->dispatch();
 *
 * Everything else (data dir, view dir, base URL, debug, mail, db, …) is
 * read via `Config::get(...)` / `Config::baseUrl()` / `Config::debug()`
 * / `Config::path(...)` from files under `APPPATH/config/`.
 */
class Bootstrap
{
    /**
     * The application environment name — drives the per-environment
     * config overlay. When set to `'production'`, any file under
     * `app/config/production/` deep-merges over the base configs;
     * same for `'staging'`, `'development'`, or whatever else you
     * coin (`'local'`, `'ci'`, `'test'`, ...).
     *
     * Three ways to set it, in precedence order (highest first):
     *
     *   1. `Bootstrap::setEnv('production')` — explicit code-level set.
     *   2. `APP_ENV` / `ENVIRONMENT` env var — auto-detected by
     *      `Config::configure()` when no arg is passed.
     *   3. Default `'dev'` baked into `Config`.
     *
     * Reading it back: `Bootstrap::env()` — returns the resolved value.
     *
     * Recommended ordering in `www/index.php`:
     *
     *   \Cloude\Bootstrap::initPaths(docroot: __DIR__, apppath: ...);
     *   \Cloude\Bootstrap::setEnv(\Cloude\Config::env('APP_ENV', 'production'));
     *   \Cloude\Config::configure(APPPATH . '/config');
     *   \Cloude\Bootstrap::run();
     */
    public static ?string $env = null;

    /**
     * Set the application environment and propagate it to `Config` so
     * any subsequent `Config::get()` reads the per-env overlay. Safe to
     * call before OR after `Config::configure()` — the loader is
     * idempotent and flushes its cache on every env change.
     */
    public static function setEnv(string $env): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $env)) {
            throw new \InvalidArgumentException(
                "Invalid environment name '$env' (allowed: A-Za-z0-9_-)",
            );
        }
        self::$env = $env;
        Config::setEnvironment($env);
    }

    /**
     * Resolved environment name. Falls back to `APP_ENV` /
     * `ENVIRONMENT` env vars, then to `Config`'s default (`'dev'`).
     */
    public static function env(): string
    {
        if (self::$env !== null) {
            return self::$env;
        }
        $detected = Config::env('APP_ENV') ?? Config::env('ENVIRONMENT');
        return $detected ?? Config::environment();
    }

    /**
     * Defines the three directory constants the framework expects.
     *
     * - `DOCROOT`   public web root              (typically `www/`)
     * - `APPPATH`   application root              (typically `app/`)
     * - `BASEPATH`  project root                  (parent of both)
     *
     * Trailing slashes are stripped. Already-defined constants are left
     * untouched, so callers may pre-define any of them (handy in tests).
     */
    public static function initPaths(
        string $docroot,
        string $apppath,
        ?string $basepath = null,
    ): void {
        if (!defined('DOCROOT')) {
            define('DOCROOT', rtrim($docroot, DIRECTORY_SEPARATOR . '/'));
        }
        if (!defined('APPPATH')) {
            define('APPPATH', rtrim($apppath, DIRECTORY_SEPARATOR . '/'));
        }
        if (!defined('BASEPATH')) {
            define('BASEPATH', rtrim($basepath ?? dirname($apppath), DIRECTORY_SEPARATOR . '/'));
        }
    }
    /**
     * Built-in PHP dev server (cli-server) static-file passthrough.
     *
     * Returns true when the request targets a real file inside $docroot.
     * The caller MUST `return false;` from the router script in that case
     * (cli-server's documented convention to delegate to its static handler).
     *
     * In production Apache, .htaccess handles this — the cli-server branch is
     * a no-op (returns false) so the router runs as usual.
     */
    public static function serveStaticIfExists(string $docroot): bool
    {
        if (PHP_SAPI !== 'cli-server') {
            return false;
        }
        $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($reqPath === '/') {
            return false;
        }
        return is_file(rtrim($docroot, '/') . $reqPath);
    }

    /**
     * Standard front-controller bootstrap:
     *   1. Default timezone — applied from `Config::get('app.timezone')`
     *      (FW ships `config/app.php` with `'timezone' => 'UTC'`).
     *   2. ob_start() — so the error handler can swap a partial response for
     *      a 503 if an exception fires mid-render.
     *   3. ErrorHandler::register() — global 503 handler with HTML/JSON/MD
     *      negotiation. Uses $viewBase to look up 500.html.php overrides;
     *      falls back to the View base path, then to framework defaults.
     *   4. View::setBasePath($viewBase) — when $viewBase is provided.
     *
     * Both arguments are optional. When omitted, the values are read from
     * `Config::debug()` and `Config::path('views')` (which in turn read
     * `app.debug` and `app.paths.views` from the config files). This is
     * the recommended way to wire things since v0.35 — keep `www/index.php`
     * minimal and let `app/config/app.php` own the knobs.
     */
    public static function run(?bool $debug = null, ?string $viewBase = null): void
    {
        // Sync env: if the property was assigned directly (without
        // setEnv()) after Config::configure() already ran, propagate
        // it now so the per-env overlay is in effect before any
        // Config::get() below.
        if (self::$env !== null && Config::environment() !== self::$env) {
            Config::setEnvironment(self::$env);
        }

        // Apply the configured timezone before anything date-related runs.
        // `Cloude\DateTime`, the mail `Date:` header, and every native PHP
        // date function use the default timezone — setting it once at boot
        // is the only sane place. Falls back to 'UTC' if the FW's bundled
        // `config/app.php` is somehow not on the search path.
        $tz = Config::get('app.timezone', 'UTC');
        date_default_timezone_set(is_string($tz) && $tz !== '' ? $tz : 'UTC');

        // Register short-name aliases when declared in `app.aliases`.
        // Lets views write `View::e(...)` / `Str::slug(...)` instead of
        // `\Cloude\View::e(...)`. Opt-in, no global pollution by default.
        $aliases = Config::get('app.aliases');
        if (is_array($aliases)) {
            self::registerAliases($aliases);
        }

        ob_start();

        $debug ??= Config::debug();

        if ($viewBase === null) {
            $viewBase = Config::path('views', null);
        }
        if ($viewBase !== null && $viewBase !== '') {
            View::setBasePath($viewBase);
        }

        $effective = $viewBase ?? (View::getBasePath() ?: null);
        Http\ErrorHandler::register(debug: $debug, viewBase: $effective);
    }

    /**
     * Register short-name aliases for framework classes so views (and
     * any other code) can drop the `Cloude\` prefix. Driven by the
     * `app.aliases` config key:
     *
     *   // app/config/app.php
     *   return [
     *       'aliases' => ['View', 'Input', 'Str', 'DateTime'],
     *   ];
     *
     *   // Anywhere afterwards:
     *   echo View::e($title);
     *   echo Str::slug($post->title);
     *
     * Each entry must be a string. The framework looks up
     * `\Cloude\<entry>` and aliases it to the bare short name. Entries
     * whose short name is already taken (e.g. you have your own
     * `App\View` aliased globally) are skipped silently — no override
     * of existing symbols, so consumer code stays safe.
     *
     * @param array<mixed> $aliases
     */
    private static function registerAliases(array $aliases): void
    {
        foreach ($aliases as $short) {
            if (!is_string($short) || $short === '') {
                continue;
            }
            // Skip when the short name is already taken (user class,
            // PHP built-in, previous alias). Don't try to be clever.
            if (class_exists($short, false)
                || interface_exists($short, false)
                || trait_exists($short, false)
            ) {
                continue;
            }
            $full = 'Cloude\\' . $short;
            if (!class_exists($full, true)) {
                continue;
            }
            class_alias($full, $short);
        }
    }
}
