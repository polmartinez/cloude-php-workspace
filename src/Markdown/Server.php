<?php

declare(strict_types=1);

namespace Cloude\Markdown;

use Cloude\Http\Cache;

/**
 * Serves a markdown file from disk with proper HTTP semantics:
 *
 *   - 404 + short cache when the file is missing
 *   - 304 when the client already has a fresh copy (Last-Modified / ETag)
 *   - 200 with text/markdown + canonical Link header otherwise
 *
 * If the file is stored as `.md.gz` and the client accepts gzip, the raw
 * gzipped bytes are streamed with `Content-Encoding: gzip` — saving CPU
 * and bandwidth. Otherwise the body is decoded on the fly.
 */
class Server
{
    public static function serve(string $mdPath, string $canonicalUrl, string $notFoundBody = "# 404\n\nNot found."): void
    {
        if (!File::exists($mdPath)) {
            Cache::notFound();
            http_response_code(404);
            header('Content-Type: text/markdown; charset=UTF-8');
            echo $notFoundBody;
            return;
        }
        if (Cache::conditionalGet(File::mtime($mdPath))) {
            return;
        }

        header('Content-Type: text/markdown; charset=UTF-8');
        Cache::ok();
        header('Link: <' . $canonicalUrl . '>; rel="canonical"');

        $gzPath = $mdPath . '.gz';
        $acceptsGzip = str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip');
        if (file_exists($gzPath) && $acceptsGzip) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            readfile($gzPath);
            return;
        }
        echo File::read($mdPath);
    }
}
