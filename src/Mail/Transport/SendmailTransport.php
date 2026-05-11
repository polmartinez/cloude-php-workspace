<?php

declare(strict_types=1);

namespace Cloude\Mail\Transport;

use Cloude\Mail\Message;
use Cloude\Mail\Transport;

/**
 * Piped delivery through the local `sendmail` binary.
 *
 * Defaults match the common Debian / Ubuntu install path. Override
 * `$path` if your sendmail (or postfix's sendmail-compat) lives
 * elsewhere, or pass `'msmtp'` / similar for a sendmail-API drop-in.
 *
 * `-t` reads the To/Cc/Bcc headers from the message body; `-i`
 * disables the legacy "single dot ends input" rule. Both are
 * standard for programmatic use.
 *
 * Bcc note: with `-t`, sendmail will look at the `Bcc:` header to pick
 * up recipients AND strip it from the outgoing message. We don't
 * include `Bcc:` in `Message::build()`, so to support bcc here we
 * inject a one-shot `Bcc:` header before piping, then sendmail removes
 * it. (Without this step, bcc recipients silently don't get the mail.)
 */
final class SendmailTransport implements Transport
{
    public function __construct(
        private string $path = '/usr/sbin/sendmail',
        private string $args = '-t -i',
    ) {}

    public function send(array $email): bool
    {
        $message = Message::build($email);

        // Inject Bcc: header so `sendmail -t` picks bcc recipients up.
        // We splice it after the existing headers (before the blank
        // line that terminates the header block).
        if (!empty($email['bcc'])) {
            $bcc = implode(', ', (array) $email['bcc']);
            $message = preg_replace(
                '/\r\n\r\n/',
                "\r\nBcc: $bcc\r\n\r\n",
                $message,
                1,
            );
        }

        $cmd = $this->path . ' ' . $this->args;
        $proc = @popen($cmd, 'wb');
        if ($proc === false) {
            return false;
        }

        $bytes = fwrite($proc, $message);
        $exit  = pclose($proc);

        return $bytes !== false && $exit === 0;
    }
}
