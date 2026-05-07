<?php

declare(strict_types=1);

namespace Cloude;

/**
 * One-stop entry-point bootstrap. A typical www/index.php becomes:
 *
 *   require_once dirname(__DIR__) . '/vendor/autoload.php';
 *   require_once dirname(__DIR__) . '/app/config.php';
 *
 *   if (\Cloude\Bootstrap::serveStaticIfExists(__DIR__)) {
 *       return false;
 *   }
 *
 *   \Cloude\Bootstrap::run(debug: DEBUG, viewBase: dirname(__DIR__) . '/app/views');
 *
 *   $router = new \Cloude\Router(BASE_URL);
 *   // ...routes...
 *   $router->dispatch();
 */
class Bootstrap
{
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
     */
    public static function run(bool $debug = false, ?string $viewBase = null): void
    {
        ob_start();

        if ($viewBase !== null && $viewBase !== '') {
            View::setBasePath($viewBase);
        }

        $effective = $viewBase ?? (View::getBasePath() ?: null);
        Http\ErrorHandler::register(debug: $debug, viewBase: $effective);
    }
}
