<?php

declare(strict_types=1);

namespace Cloude\Http;

/**
 * HTTP cache helpers: response cache headers and conditional GET.
 *
 * - ok()/notFound()/unavailable() emit CDN-Cache-Control headers tuned for
 *   typical content-driven sites (long TTL on 200, short on 404, no-store on 5xx).
 * - conditionalGet() implements If-Modified-Since / If-None-Match. Returns true
 *   when the client already has a fresh copy (and a 304 has been sent).
 */
class Cache
{
    /**
     * CDN cache headers for a successful (200) response.
     */
    public static function ok(int $stimeout = 86400, int $timeout = 0): void
    {
        header(sprintf(
            'Cache-Control: s-maxage=%d, max-age=%d, stale-if-error=%d, stale-while-revalidate=%d',
            $stimeout,
            $timeout,
            $stimeout,
            $stimeout,
        ));
        header(sprintf(
            'CDN-Cache-Control: s-maxage:%d, max-age=%d, stale-if-error=%d, stale-while-revalidate=%d',
            $stimeout,
            $timeout,
            $stimeout,
            $stimeout,
        ));
    }

    /**
     * CDN cache headers for a 404 response. Short TTL so new content
     * becomes visible fast.
     */
    public static function notFound(int $timeout = 120): void
    {
        header('Cache-Control: max-age=' . $timeout);
        header('CDN-Cache-Control: max-age=' . $timeout);
    }

    /**
     * 503 — content known to exist but not yet ready. Don't cache,
     * and tell crawlers to come back later instead of treating the URL as gone.
     */
    public static function unavailable(int $retryAfter = 600): void
    {
        header('Cache-Control: no-store');
        header('CDN-Cache-Control: no-store');
        header('Retry-After: ' . $retryAfter);
    }

    /**
     * HTTP conditional GET: emits Last-Modified + ETag and returns true if the
     * client already has a fresh copy (in which case a 304 is sent).
     *
     * Typical usage at the start of a detail route:
     *
     *   if (Cache::conditionalGet(filemtime($path))) {
     *       return;
     *   }
     *
     * @param int $mtime Unix timestamp of the resource (e.g. filemtime of the .md)
     */
    public static function conditionalGet(int $mtime): bool
    {
        if ($mtime <= 0) {
            return false;
        }
        $lastMod = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $etag    = '"' . md5((string) $mtime) . '"';

        header('Last-Modified: ' . $lastMod);
        header('ETag: ' . $etag);

        $ifModSince  = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

        if (
            ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag)
            || ($ifModSince !== '' && @strtotime($ifModSince) >= $mtime)
        ) {
            http_response_code(304);
            return true;
        }
        return false;
    }
}
