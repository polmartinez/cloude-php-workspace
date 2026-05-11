<?php

declare(strict_types=1);

namespace Cloude\Mail;

use Cloude\Mail\Transport\SendmailTransport;
use Cloude\Mail\Transport\SmtpTransport;

/**
 * Public mail API — wraps a `Transport` and applies validation +
 * defaults on every send.
 *
 * Two construction paths:
 *
 *   $mailer = new Mailer($transport, ['from' => 'noreply@x.com']);
 *
 *   $mailer = Mailer::fromConfig(\Cloude\Config::get('mail'));
 *
 * The factory builds the right transport from `$config['transport']`
 * (`'smtp'` or `'sendmail'`). Config shape:
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
    /**
     * @param array<string,mixed> $defaults Applied to every send (e.g. default 'from')
     */
    public function __construct(
        private Transport $transport,
        private array $defaults = [],
    ) {}

    /**
     * @param array<string,mixed> $config
     */
    public static function fromConfig(array $config): self
    {
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
