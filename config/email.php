<?php

declare(strict_types=1);

/**
 * Framework-shipped defaults for `Cloude\Mail\Mailer::forge()`.
 *
 * Auto-loaded by `Cloude\Config` whenever a consumer app calls
 * `Config::configure(APPPATH . '/config')`. The app's own
 * `app/config/email.php` is deep-merged onto this base, so projects
 * only need to declare the keys that differ from the defaults below.
 *
 * Sensible defaults: sendmail transport, modern SMTP defaults (port
 * 587, STARTTLS on, 30s timeout). No credentials shipped — those must
 * come from the app's config and/or env vars.
 */

return [
    // Default transport: 'sendmail' works on any box with a local MTA.
    // Apps that need SMTP override this to 'smtp' and add host/user/pass.
    'transport' => 'sendmail',

    // ── Sendmail-only defaults ────────────────────────────────────────────
    'path' => '/usr/sbin/sendmail',
    'args' => '-t -i',

    // ── SMTP-only defaults ────────────────────────────────────────────────
    // App config is expected to override 'host', and (typically) 'user'
    // and 'pass'. STARTTLS is on by default — flip 'tls' off only if the
    // server requires plain submission.
    'port'    => 587,
    'tls'     => true,
    'timeout' => 30,
];
