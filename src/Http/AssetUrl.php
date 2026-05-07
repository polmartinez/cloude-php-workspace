<?php

declare(strict_types=1);

namespace Cloude\Http;

/**
 * Versioned asset URLs for cache-busting.
 *
 * Pattern: /{mtime}/assets/css/styles.css → rewritten by Apache back to
 * /assets/css/styles.css. The version prefix is transparent to the file
 * system and auto-bumps on each deploy without manual intervention.
 *
 * Required Apache rewrite rule:
 *
 *   RewriteRule ^[0-9]+/assets/(.*)$ /assets/$1 [L]
 *
 * Configure once at boot:
 *
 *   AssetUrl::configure(
 *       baseUrl: BASE_URL,
 *       assetsDir: __DIR__ . '/../www/assets',
 *   );
 *
 * Then use:
 *
 *   AssetUrl::get('css/styles.css'); // → "{BASE_URL}/{mtime}/assets/css/styles.css"
 */
class AssetUrl
{
    private static string $baseUrl = '';
    private static string $assetsDir = '';

    /** @var array<string,int> */
    private static array $cache = [];

    public static function configure(string $baseUrl, string $assetsDir): void
    {
        self::$baseUrl = rtrim($baseUrl, '/');
        self::$assetsDir = rtrim($assetsDir, '/');
        self::$cache = [];
    }

    /**
     * Returns a versioned URL for the asset at $relPath (relative to assetsDir).
     *
     * Under the PHP built-in dev server (cli-server) the .htaccess rewrite is
     * not active, so the plain `/assets/...` URL is returned instead.
     */
    public static function get(string $relPath): string
    {
        $rel = ltrim($relPath, '/');

        if (PHP_SAPI === 'cli-server') {
            return self::$baseUrl . '/assets/' . $rel;
        }

        if (!isset(self::$cache[$rel])) {
            $full = self::$assetsDir . '/' . $rel;
            self::$cache[$rel] = file_exists($full) ? (int) filemtime($full) : time();
        }
        return self::$baseUrl . '/' . self::$cache[$rel] . '/assets/' . $rel;
    }
}
