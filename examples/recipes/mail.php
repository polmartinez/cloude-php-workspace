<?php

declare(strict_types=1);

/**
 * Recipe: send email via SMTP or sendmail, picked by config.
 *
 * The framework ships `Cloude\Mail\Mailer` with three transports:
 *
 *   - SmtpTransport     real mail gateway, AUTH LOGIN, STARTTLS
 *   - SendmailTransport pipes through the local /usr/sbin/sendmail binary
 *   - MemoryTransport   collects messages in-memory for tests
 *
 * Configuration lives in `app/config/mail.php` (with per-env overrides
 * at `app/config/<env>/mail.php`). Dev uses sendmail to a local
 * postfix or `msmtp`; prod uses SMTP to a transactional gateway.
 *
 * For DKIM, OAuth XOAUTH2, attachments, batching → use
 * `symfony/mailer` or `phpmailer/phpmailer` directly. This module is
 * the 80% case in ~400 lines.
 */

use Cloude\Config;
use Cloude\Mail\Mailer;

// ── 1. Bootstrap: define the mail config alongside the rest ──────────────────
//
// app/config/mail.php (base — committed):
//
//   return [
//       'transport' => 'sendmail',            // dev default
//       'path'      => '/usr/sbin/sendmail',  // optional, this is the default
//       'from'      => 'dev@localhost',
//   ];
//
// app/config/prod/mail.php (override — usually .gitignored or env-sourced):
//
//   return [
//       'transport' => 'smtp',
//       'host'      => 'smtp.mailgun.org',
//       'port'      => 587,
//       'user'      => getenv('SMTP_USER'),
//       'pass'      => getenv('SMTP_PASS'),
//       'tls'       => true,
//       'from'      => 'noreply@example.com',
//       'reply_to'  => 'support@example.com',
//   ];

Config::configure(__DIR__ . '/../app/config');

// ── 2. Build a Mailer — pick the shortest path that fits ────────────────────
//
// Zero-config, just send via local sendmail (no config files needed):
//
//   $mailer = new Mailer();
//
// Explicit transport (tests / one-off scripts):
//
//   $mailer = new Mailer(new \Cloude\Mail\Transport\MemoryTransport());
//   $mailer = new Mailer(new \Cloude\Mail\Transport\SmtpTransport(
//       host: 'smtp.example.com', port: 587, user: '...', pass: '...',
//   ), ['from' => 'noreply@x.com']);
//
// From an explicit config array:
//
//   $mailer = Mailer::forge(['transport' => 'smtp', 'host' => '...', ...]);
//
// Auto-config from Cloude\Config — the call site shrinks to one line
// once `app/config/mail.php` exists:

$mailer = Mailer::forge();                              // reads Config::get('mail')

// ── 3. Send ─────────────────────────────────────────────────────────────────

$mailer->send([
    'to'      => 'user@example.com',
    'subject' => 'Welcome',
    'body'    => 'Hi! Thanks for signing up.',
]);

// HTML
$mailer->send([
    'to'      => 'user@example.com',
    'subject' => 'Newsletter',
    'body'    => '<h1>Hello</h1><p>This week\'s links…</p>',
    'html'    => true,
]);

// Multiple recipients, cc, bcc
$mailer->send([
    'to'       => ['ops1@x.com', 'ops2@x.com'],
    'cc'       => 'manager@x.com',
    'bcc'      => 'audit@x.com',                 // hidden from To/Cc recipients
    'subject'  => 'Deploy summary',
    'body'     => 'Release v1.2.3 deployed.',
    'reply_to' => 'noreply@x.com',
    'headers'  => [
        'X-Mailer'       => 'cloude/0.27',
        'X-Auto-Response' => 'No',
    ],
]);

// ── 4. Testing — swap the transport, no network involved ────────────────────
//
// In a Cloude\Testing\TestCase setUp() / test fixture:
//
//   use Cloude\Mail\Mailer;
//   use Cloude\Mail\Transport\MemoryTransport;
//
//   $transport = new MemoryTransport();
//   $mailer    = new Mailer($transport, ['from' => 'test@x.com']);
//
//   // ... exercise code that calls $mailer->send(...) ...
//
//   self::assertCount(1, $transport->sent());
//   self::assertSame('Welcome', $transport->sent()[0]['subject']);
//   self::assertStringContainsString('Subject: Welcome',
//                                    $transport->sent()[0]['_rendered']);

// ── 5. Defaults shrink call sites ───────────────────────────────────────────
//
// Setting `from` (and optionally `reply_to`) in the config means every
// send() call can skip those fields. The Mailer merges defaults first:
//
//   $mailer->send([
//       'to'      => 'user@x.com',
//       'subject' => 'No-noise call site',
//       'body'    => '...',
//   ]);
//
// → 'from' / 'reply_to' come from the config; caller doesn't repeat them.

// ── 6. Provider configs (transactional gateways via SMTP) ───────────────────
//
// All three popular providers speak standard SMTP + AUTH LOGIN + STARTTLS
// on port 587. Cloude\Mail\Transport\SmtpTransport works against each one
// out of the box — only credentials and host change.
//
// **MailerSend** — https://www.mailersend.com
//
//   return [
//       'transport' => 'smtp',
//       'host'      => 'smtp.mailersend.net',
//       'port'      => 587,
//       'user'      => getenv('MAILERSEND_USER'),    // generated in dashboard
//       'pass'      => getenv('MAILERSEND_PASS'),
//       'tls'       => true,
//       'from'      => 'noreply@yourdomain.com',     // must be a verified domain
//   ];
//
// **Mailgun** — https://www.mailgun.com
//
//   return [
//       'transport' => 'smtp',
//       'host'      => 'smtp.mailgun.org',           // 'smtp.eu.mailgun.org' for EU region
//       'port'      => 587,
//       'user'      => 'postmaster@mg.yourdomain.com',
//       'pass'      => getenv('MAILGUN_SMTP_PASS'),
//       'tls'       => true,
//       'from'      => 'noreply@yourdomain.com',
//   ];
//
// **SendGrid** — https://sendgrid.com
//
//   return [
//       'transport' => 'smtp',
//       'host'      => 'smtp.sendgrid.net',
//       'port'      => 587,
//       'user'      => 'apikey',                     // literal string 'apikey'
//       'pass'      => getenv('SENDGRID_API_KEY'),   // the SendGrid API key
//       'tls'       => true,
//       'from'      => 'noreply@yourdomain.com',     // must be a verified sender
//   ];
//
// Port 465 (implicit TLS instead of STARTTLS) also works on all three —
// pass `'host' => 'ssl://smtp.example.com'` + `'tls' => false`. Port 587 +
// STARTTLS is the recommended default and what the framework expects when
// nothing is specified.
