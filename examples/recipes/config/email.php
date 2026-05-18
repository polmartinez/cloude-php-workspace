<?php

declare(strict_types=1);

/**
 * Email transport configuration. Copy this file to `app/config/email.php`
 * in your project and tailor the values. `Cloude\Mail\Mailer::forge()`
 * picks it up automatically when `Cloude\Config::configure(APPPATH . '/config')`
 * has been called at boot.
 *
 *   $mailer = \Cloude\Mail\Mailer::forge();
 *   $mailer->send(['to' => 'a@b', 'subject' => 'hi', 'body' => '...']);
 *
 * Per-environment overrides go under `app/config/{env}/email.php` and
 * are deep-merged onto this base. Common pattern: keep credentials and
 * production host in `prod/email.php`, leave a memory / sendmail
 * transport here for dev. See `examples/recipes/config.php` for the
 * full multi-env loader walkthrough.
 *
 * Secrets belong in env vars (`Cloude\Config::env('SMTP_PASS')`) — not
 * in this file. Don't commit a populated `prod/email.php`.
 */

use Cloude\Config;

return [
    // ── Transport ────────────────────────────────────────────────────────
    //
    // 'smtp'     → Cloude\Mail\Transport\SmtpTransport
    //              AUTH LOGIN + STARTTLS. Verified compatible with
    //              MailerSend / Mailgun / SendGrid (any provider that
    //              speaks plain SMTP submission on 587).
    //
    // 'sendmail' → Cloude\Mail\Transport\SendmailTransport
    //              Pipes the RFC 5322 message to a local sendmail
    //              binary. Right for dev boxes and servers with a
    //              configured MTA.
    'transport' => Config::env('MAIL_TRANSPORT', 'sendmail'),

    // ── SMTP-only ────────────────────────────────────────────────────────
    'host'    => Config::env('SMTP_HOST', 'smtp.example.com'),
    'port'    => (int) (Config::env('SMTP_PORT') ?? 587),
    'user'    => Config::env('SMTP_USER'),
    'pass'    => Config::env('SMTP_PASS'),
    'tls'     => Config::boolEnv('SMTP_TLS', true),
    'timeout' => 30,

    // ── Sendmail-only ────────────────────────────────────────────────────
    'path' => '/usr/sbin/sendmail',     // default; tweak when packaged differently
    'args' => '-t -i',                  // default flags

    // ── Defaults applied to every send() ────────────────────────────────
    //
    // `from` is required either here or per-send. Anything you set here
    // becomes the fallback when a `send()` call omits the field.
    'from'     => Config::env('MAIL_FROM', 'no-reply@example.com'),
    'reply_to' => Config::env('MAIL_REPLY_TO'),
];
