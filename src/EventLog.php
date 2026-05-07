<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Fire-and-forget POST to a configured webhook for usage analytics.
 *
 * Configure the destination URL once at boot:
 *
 *   \Cloude\EventLog::configure('https://webhook.site/<uuid>');
 *
 * Or rely on the EVENT_LOG_WEBHOOK constant (if defined and non-empty)
 * — useful when consumers already define it in their config.
 *
 * Empty / undefined URL = disabled (no-op). Network call uses tight timeouts
 * so it never adds noticeable latency to the response, and any failure is
 * silently swallowed. NEVER use for anything that matters operationally.
 */
class EventLog
{
    private static ?string $url = null;
    private static bool $configured = false;
    private static bool $disabled = false;

    /** @var array<int, array{url:string, body:array<string,mixed>}> */
    private static array $pending = [];

    /**
     * Sets the webhook URL. Pass an empty string to disable.
     */
    public static function configure(string $url): void
    {
        self::$url = $url;
        self::$configured = true;
        self::$disabled = ($url === '');
    }

    /**
     * Enqueue an event. Actual HTTP POST is deferred to flush(), which runs
     * automatically via a shutdown handler AFTER the response is sent to the
     * client (using fastcgi_finish_request when available). Net result: zero
     * latency added to the user-visible request when running under PHP-FPM.
     *
     * Under cli-server / local dev there's no fastcgi_finish_request, so the
     * POST happens synchronously at shutdown — still after the response body
     * is written, but the client may wait for the full request to complete.
     *
     * @param array<string,mixed> $payload
     */
    public static function send(array $payload): void
    {
        if (self::$disabled) {
            return;
        }

        $url = self::resolveUrl();
        if ($url === '') {
            self::$disabled = true;
            return;
        }
        if (!function_exists('curl_init')) {
            self::$disabled = true;
            return;
        }

        $payload['ts'] = gmdate('c');
        $payload['ip'] = self::clientIp();
        $payload['ua'] = mb_strimwidth((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);

        if (empty(self::$pending)) {
            register_shutdown_function([self::class, 'flush']);
        }
        self::$pending[] = ['url' => $url, 'body' => $payload];
    }

    /**
     * Internal: flushes pending events. Called automatically at shutdown.
     */
    public static function flush(): void
    {
        if (empty(self::$pending)) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach (self::$pending as $event) {
            $body = json_encode($event['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                continue;
            }
            $ch = curl_init($event['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST              => true,
                CURLOPT_POSTFIELDS        => $body,
                CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_NOSIGNAL          => true,
                CURLOPT_CONNECTTIMEOUT_MS => 800,
                CURLOPT_TIMEOUT_MS        => 2000,
                CURLOPT_FAILONERROR       => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.5);
        } while ($running > 0);
        foreach ($handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        self::$pending = [];
    }

    private static function resolveUrl(): string
    {
        if (self::$configured) {
            return (string) self::$url;
        }
        if (defined('EVENT_LOG_WEBHOOK')) {
            return (string) constant('EVENT_LOG_WEBHOOK');
        }
        return '';
    }

    /**
     * Best-effort client IP (handles common reverse-proxy headers).
     */
    private static function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR']  ?? null,
            $_SERVER['REMOTE_ADDR']           ?? null,
        ];
        foreach ($candidates as $v) {
            if (!$v) {
                continue;
            }
            $first = trim(explode(',', $v)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return '';
    }
}
