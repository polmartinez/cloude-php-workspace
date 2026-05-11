<?php

declare(strict_types=1);

namespace Cloude\Mail\Transport;

use Cloude\Mail\Message;
use Cloude\Mail\Transport;

/**
 * Raw SMTP client. Connects via `fsockopen`, speaks the protocol
 * line-by-line (CRLF), supports STARTTLS + AUTH LOGIN.
 *
 * Designed for the everyday case: send through a transactional mail
 * gateway (Mailgun, SES, Postmark, your own postfix) on port 587 with
 * STARTTLS. Port 465 / implicit TLS is also supported when you pass
 * `'ssl://host'` via the `host` config.
 *
 * **Deliberately narrow**:
 *   - AUTH LOGIN only. (PLAIN / CRAM-MD5 add complexity for little win.)
 *   - One message per connection. No pipelining.
 *   - No 8BITMIME, no SMTPUTF8 negotiation — UTF-8 bodies travel as
 *     Quoted-Printable would-be-nice but we send raw and rely on the
 *     server to accept (most do).
 *   - On any protocol error: throws RuntimeException. Caller wraps.
 *
 * For DKIM, OAuth XOAUTH2, async batching, attachments → use
 * `symfony/mailer` or `phpmailer/phpmailer` directly. This class is
 * the 80% case in ~150 lines.
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port = 587,
        private ?string $user = null,
        private ?string $pass = null,
        private bool $tls = true,                                       // STARTTLS after EHLO
        private int $timeout = 30,
    ) {}

    public function send(array $email): bool
    {
        try {
            $this->connect();
            $this->ehlo();
            if ($this->tls) {
                $this->starttls();
                $this->ehlo();                                          // re-EHLO after the encrypted upgrade
            }
            if ($this->user !== null) {
                $this->authLogin();
            }
            $this->mail($email);
            return true;
        } finally {
            $this->quit();
        }
    }

    // ── protocol steps ────────────────────────────────────────────────────

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($sock === false) {
            throw new \RuntimeException(
                "SMTP connect to $this->host:$this->port failed: $errstr ($errno)",
            );
        }
        stream_set_timeout($sock, $this->timeout);
        $this->socket = $sock;
        $this->expect(220);                                             // server banner
    }

    private function ehlo(): void
    {
        $this->write('EHLO ' . self::clientHostname());
        $this->expect(250);
    }

    private function starttls(): void
    {
        $this->write('STARTTLS');
        $this->expect(220);

        // PHP < 7.2 needs explicit method flags; CRYPTO_METHOD_TLS_CLIENT
        // resolves to the best mutually-supported TLS version.
        $ok = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT,
        );
        if (!$ok) {
            throw new \RuntimeException('STARTTLS handshake failed');
        }
    }

    private function authLogin(): void
    {
        $this->write('AUTH LOGIN');
        $this->expect(334);
        $this->write(base64_encode((string) $this->user));
        $this->expect(334);
        $this->write(base64_encode((string) $this->pass));
        $this->expect(235);
    }

    /**
     * @param array<string,mixed> $email
     */
    private function mail(array $email): void
    {
        $from = Message::extractAddress((string) $email['from']);
        $this->write("MAIL FROM:<$from>");
        $this->expect(250);

        foreach (Message::envelopeRecipients($email) as $addr) {
            $this->write("RCPT TO:<$addr>");
            $this->expect([250, 251]);
        }

        $this->write('DATA');
        $this->expect(354);

        // Dot-stuffing: any line starting with "." gets escaped to ".."
        // so the server doesn't read it as end-of-DATA.
        $body = Message::build($email);
        $body = (string) preg_replace('/^\./m', '..', $body);

        $this->write($body);
        $this->write('.');                                              // end of DATA
        $this->expect(250);
    }

    private function quit(): void
    {
        if ($this->socket === null) {
            return;
        }
        @fwrite($this->socket, "QUIT\r\n");
        @fclose($this->socket);
        $this->socket = null;
    }

    // ── line I/O ──────────────────────────────────────────────────────────

    private function write(string $line): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP write on closed socket');
        }
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new \RuntimeException("SMTP write failed: $line");
        }
    }

    /**
     * Reads a (possibly multi-line) response and verifies the code is
     * one of the expected ones. Multi-line responses use `-` after the
     * code on every line except the last, which uses a space.
     *
     * @param int|list<int> $codes
     */
    private function expect(int|array $codes): void
    {
        $expected = (array) $codes;
        $lines = [];
        do {
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                throw new \RuntimeException(
                    'SMTP read failed; got: ' . implode('', $lines),
                );
            }
            $lines[] = $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        $last = end($lines);
        $code = (int) substr($last, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException(
                'SMTP unexpected response (wanted '
                . implode('/', $expected) . '): ' . trim(implode('', $lines)),
            );
        }
    }

    private static function clientHostname(): string
    {
        return gethostname() ?: 'localhost';
    }
}
