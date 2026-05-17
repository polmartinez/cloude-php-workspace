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
     *   1. ob_start() — so the error handler can swap a partial response for
     *      a 503 if an exception fires mid-render.
     *   2. ErrorHandler::register() — global 503 handler with HTML/JSON/MD
     *      negotiation. Uses $viewBase to look up 500.html.php overrides;
     *      falls back to the View base path, then to framework defaults.
     *   3. View::setBasePath($viewBase) — when $viewBase is provided.
     *
     * Both arguments are optional. When omitted, the values are read from
     * `Config::debug()` and `Config::path('views')` (which in turn read
     * `app.debug` and `app.paths.views` from the config files). This is
     * the recommended way to wire things since v0.35 — keep `www/index.php`
     * minimal and let `app/config/app.php` own the knobs.
     */
    public static function run(?bool $debug = null, ?string $viewBase = null): void
    {
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
}
