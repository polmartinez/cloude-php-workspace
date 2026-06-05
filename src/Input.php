<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Wrapper over HTTP input superglobals.
 * Normalizes access to URI, method, headers, query, body and JSON.
 */
class Input
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Returns the URI path, stripped of query string and with no double slashes.
     * Never exposes the raw $_SERVER['REQUEST_URI'] value.
     */
    public static function uri(): string
    {
        $raw = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($raw, PHP_URL_PATH) ?? '/';
        $path = '/' . ltrim($path, '/');
        return preg_replace('#/{2,}#', '/', $path);
    }

    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if (empty($key)) {
            return $_GET;
        }
        return $_GET[$key] ?? $default;
    }

    public static function post(?string $key = null, mixed $default = null): mixed
    {
        if (empty($key)) {
            return $_POST;
        }
        return $_POST[$key] ?? $default;
    }

    /**
     * Reads from `$_SERVER`. Pass no `$key` to get the full array,
     * or a key (case-sensitive — `$_SERVER` keys are upper-snake by
     * PHP convention: `REMOTE_ADDR`, `HTTP_HOST`, `REQUEST_METHOD`).
     *
     *   Input::server('REMOTE_ADDR');                 // → '10.0.0.5'
     *   Input::server('HTTP_HOST', 'localhost');      // with default
     *   Input::server();                              // full array
     *
     * For typed convenience use the dedicated helpers:
     * `method()`, `uri()`, `header('User-Agent')`, `ip()`.
     */
    public static function server(?string $key = null, mixed $default = null): mixed
    {
        if (empty($key)) {
            return $_SERVER;
        }
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Decodes the raw request body as JSON.
     * Returns null if the body is empty or not valid JSON.
     */
    public static function json(): ?array
    {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Returns the raw request body.
     */
    public static function body(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * Returns a request header value. "User-Agent" -> HTTP_USER_AGENT.
     */
    public static function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Client IP address. When $trustProxy is true, prefers the first entry of
     * X-Forwarded-For (use only when behind a proxy/CDN you control).
     */
    public static function ip(bool $trustProxy = false): string
    {
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
