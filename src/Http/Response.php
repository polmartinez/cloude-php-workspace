<?php

declare(strict_types=1);

namespace Cloude\Http;

/**
 * Response helpers — set status + Content-Type + body in one call.
 *
 * These are intentionally minimal: each helper sends headers and echoes the
 * body, then returns. There is no buffering, no chaining, no Response
 * object passed around. Use them at the end of a route handler.
 */
class Response
{
    /**
     * Sends a JSON body with `application/json; charset=utf-8`.
     *
     * Encoding flags: UNESCAPED_UNICODE | UNESCAPED_SLASHES (so URLs render
     * as "https://..." instead of "https:\/\/..."). Pass $pretty = true for
     * indented output.
     */
    public static function json(mixed $data, int $status = 200, bool $pretty = false): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode($data, $flags);
    }

    /**
     * Generic body emitter. Use the typed helpers below for common MIMEs.
     */
    public static function text(string $body, int $status = 200, string $mime = 'text/plain'): void
    {
        http_response_code($status);
        header('Content-Type: ' . $mime . '; charset=utf-8');
        echo $body;
    }

    public static function html(string $body, int $status = 200): void
    {
        self::text($body, $status, 'text/html');
    }

    public static function xml(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/xml; charset=utf-8');
        echo $body;
    }

    public static function markdown(string $body, int $status = 200): void
    {
        self::text($body, $status, 'text/markdown');
    }

    /**
     * Sends a Location redirect. Strips CRLF from $url to prevent header
     * injection. Default 302 (Found); use 301 for permanent moves.
     */
    public static function redirect(string $url, int $status = 302): void
    {
        $url = str_replace(["\r", "\n"], '', $url);
        http_response_code($status);
        header('Location: ' . $url);
    }

    /**
     * 404 with a plain-text body by default. Pass a custom MIME for JSON /
     * markdown 404s (e.g. `Response::notFound('# 404', 'text/markdown')`).
     */
    public static function notFound(string $body = '404 Not Found', string $mime = 'text/plain'): void
    {
        self::text($body, 404, $mime);
    }

    /**
     * 204 No Content — empty response, no body.
     */
    public static function noContent(): void
    {
        http_response_code(204);
    }
}
