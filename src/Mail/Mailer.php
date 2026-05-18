<?php

declare(strict_types=1);

namespace Cloude\Mail;

use Cloude\Arr;
use Cloude\Config;
use Cloude\Mail\Transport\SendmailTransport;
use Cloude\Mail\Transport\SmtpTransport;

/**
 * Public mail API — wraps a `Transport` and applies validation +
 * defaults on every send.
 *
 * Four construction paths, pick the shortest that fits:
 *
 *   1. Zero-config — sendmail with default path, no defaults:
 *
 *      $mailer = new Mailer();
 *
 *   2. Explicit transport — full control, useful in tests or scripts
 *      that build their own SmtpTransport / MemoryTransport / etc.:
 *
 *      $mailer = new Mailer(new SmtpTransport(...), ['from' => 'x@y.com']);
 *
 *   3. From a passed-in config array:
 *
 *      $mailer = Mailer::forge([
 *          'transport' => 'smtp',
 *          'host'      => '...',
 *          'from'      => '...',
 *      ]);
 *
 *   4. Auto-config from `Cloude\Config::get('email')` — the call site
 *      shrinks to one line when `Cloude\Config::configure(...)` ran at
 *      boot. The framework ships defaults at `config/email.php`
 *      (port 587, TLS on, sendmail fallback); the app's
 *      `app/config/email.php` is deep-merged on top:
 *
 *      $mailer = Mailer::forge();
 *
 * Config shape:
 *
 *   // SMTP (production)
 *   return [
 *       'transport' => 'smtp',
 *       'host'      => 'smtp.example.com',
 *       'port'      => 587,
 *       'user'      => 'app',
 *       'pass'      => getenv('SMTP_PASS'),
 *       'tls'       => true,
 *       'from'      => 'noreply@example.com',
 *   ];
 *
 *   // Sendmail (local dev)
 *   return [
 *       'transport' => 'sendmail',
 *       'path'      => '/usr/sbin/sendmail',     // optional, this is default
 *       'from'      => 'dev@localhost',
 *   ];
 *
 * Sending:
 *
 *   $mailer->send([
 *       'to'      => 'user@example.com',
 *       'subject' => 'Welcome',
 *       'body'    => '<h1>Hi</h1>',
 *       'html'    => true,
 *   ]);
 *
 * Validation throws InvalidArgumentException; transport failures
 * (network, auth, sendmail exit code) throw RuntimeException from
 * the underlying transport.
 */
final class Mailer
{
    private Transport $transport;

    /** @var array<string,mixed> */
    private array $defaults;

    /**
     * @param Transport|null       $transport Defaults to `SendmailTransport()` when null.
     * @param array<string,mixed>  $defaults  Applied to every send (e.g. default 'from')
     */
    public function __construct(
        ?Transport $transport = null,
        array $defaults = [],
    ) {
        $this->transport = $transport ?? new SendmailTransport();
        $this->defaults = $defaults;
    }

    /**
     * Builds a Mailer from a config array, dispatching the right
     * transport on `$config['transport']`.
     *
     * Pass `null` (or omit) to read from `Cloude\Config::get('email')`
     * (or the legacy `'mail'` key for back-compat) — the call site
     * shrinks to `Mailer::forge()` once you've wired
     * `Cloude\Config::configure(...)` at boot. Drop a config file like:
     *
     *   // app/config/email.php
     *   return [
     *       'transport' => 'smtp',
     *       'host'      => 'smtp.example.com',
     *       'port'      => 587,
     *       'user'      => Cloude\Config::env('SMTP_USER'),
     *       'pass'      => Cloude\Config::env('SMTP_PASS'),
     *       'tls'       => true,
     *       'from'      => 'no-reply@example.com',
     *   ];
     *
     * **Partial config also merges with `Cloude\Config::get('email')`** —
     * if you pass an array, it's deep-merged on top of whatever the
     * framework's `config/email.php` + the app's `app/config/email.php`
     * already declared. So:
     *
     *   Mailer::forge(['transport' => 'smtp', 'host' => 'smtp.mailersend.net']);
     *
     * still inherits `port`, `tls`, `timeout`, `from` etc. from your
     * existing config — you only declare what's different. Pass keys
     * to NULL them out explicitly if you want to clear something.
     *
     * Edge case: if `Config` isn't wired (no `configure()` call) the
     * passed array is used as-is. Built-in fallbacks for SMTP defaults
     * (port=587, tls=true, timeout=30) still kick in via the constructor
     * argument defaults below.
     *
     * @param array<string,mixed>|null $config
     */
    public static function forge(?array $config = null): self
    {
        // Merge layer: framework defaults (config/email.php) + app
        // overrides (app/config/email.php) — already deep-merged by
        // Cloude\Config. We pull this whenever Config is wired, then
        // overlay the caller-supplied $config (if any) on top.
        $merged = [];
        if (Config::paths() !== []) {
            $loaded = Config::get('email');
            if (is_array($loaded)) {
                $merged = $loaded;
            }
        }
        if ($config !== null) {
            $merged = $merged === [] ? $config : Arr::merge($merged, $config);
        }

        if ($merged === []) {
            throw new \InvalidArgumentException(
                "Mailer::forge() expects an 'email' entry in "
                . 'Cloude\\Config (app/config/email.php) or an explicit '
                . 'config array. The framework ships defaults at config/email.php; '
                . "make sure Cloude\\Config::configure(APPPATH . '/config') "
                . 'ran at boot.',
            );
        }
        $config = $merged;

        $type = $config['transport'] ?? throw new \InvalidArgumentException(
            "Mail config needs a 'transport' key (use 'smtp' or 'sendmail')",
        );

        $transport = match ($type) {
            'smtp' => new SmtpTransport(
                host:    (string) ($config['host'] ?? throw new \InvalidArgumentException("'smtp' transport needs 'host'")),
                port:    (int) ($config['port'] ?? 587),
                user:    isset($config['user']) ? (string) $config['user'] : null,
                pass:    isset($config['pass']) ? (string) $config['pass'] : null,
                tls:     (bool) ($config['tls']  ?? true),
                timeout: (int) ($config['timeout'] ?? 30),
            ),
            'sendmail' => new SendmailTransport(
                path: (string) ($config['path'] ?? '/usr/sbin/sendmail'),
                args: (string) ($config['args'] ?? '-t -i'),
            ),
            default => throw new \InvalidArgumentException(
                "Unknown mail transport '$type' (supported: smtp, sendmail)",
            ),
        };

        $defaults = [];
        foreach (['from', 'reply_to'] as $k) {
            if (isset($config[$k])) {
                $defaults[$k] = $config[$k];
            }
        }
        if (isset($config['dkim']) && is_array($config['dkim']) && $config['dkim'] !== []) {
            // Carried into every send() so Message::build() can sign;
            // overridable per-send by passing a different 'dkim' array.
            $defaults['dkim'] = $config['dkim'];
        }

        return new self($transport, $defaults);
    }

    /**
     * Sends one email. Defaults are merged in first; passed fields win.
     *
     * @param array<string,mixed> $email
     */
    public function send(array $email): bool
    {
        $email = array_replace($this->defaults, $email);

        // to/from need an actual value — sending with no recipient is
        // a bug. subject/body just need to be present (an empty subject
        // is weird-but-legal, an empty body is fine for "ping" style
        // notifications).
        foreach (['to', 'from'] as $required) {
            if (empty($email[$required])) {
                throw new \InvalidArgumentException(
                    "Email is missing required field '$required'",
                );
            }
        }
        foreach (['subject', 'body'] as $required) {
            if (!array_key_exists($required, $email)) {
                throw new \InvalidArgumentException(
                    "Email is missing required field '$required'",
                );
            }
        }

        return $this->transport->send($email);
    }

    /**
     * Escape hatch for tests / instrumentation that need to inspect the
     * configured transport.
     */
    public function transport(): Transport
    {
        return $this->transport;
    }
}
