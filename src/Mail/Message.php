<?php

declare(strict_types=1);

namespace Cloude\Mail;

/**
 * Builds an RFC 5322 message string from an email array. Shared by
 * every `Transport` implementation — the wire format is the same
 * whether you're piping into sendmail or speaking SMTP.
 *
 * Output uses CRLF line endings (mandatory for SMTP). Sendmail
 * tolerates LF, but CRLF is the safer common denominator.
 *
 * DKIM: when the email array carries a `'dkim'` key (or the Mailer's
 * defaults do), the built message is signed by {@see DkimSigner} before
 * `build()` returns. See `DkimSigner` for the config shape.
 *
 * Deliberately narrow:
 *   - No attachments (multipart/mixed). For PDFs/etc. install
 *     `symfony/mailer` or `phpmailer/phpmailer` directly.
 *   - No HTML + plain alternative. Pick one with the `html` flag.
 *   - No bounce / tracking headers — add them via the `headers` field
 *     if you need them.
 *
 * RFC 2047 (encoded-word) is used for non-ASCII Subject / display
 * names so subjects with accents make it through old servers intact.
 */
final class Message
{
    /**
     * @param array<string,mixed> $email
     */
    public static function build(array $email): string
    {
        $html = (bool) ($email['html'] ?? false);

        $headers = [
            'To'           => self::joinAddresses($email['to']),
            'From'         => $email['from'],
            'Subject'      => self::encodeHeader((string) $email['subject']),
            'MIME-Version' => '1.0',
            'Content-Type' => ($html ? 'text/html' : 'text/plain') . '; charset=UTF-8',
            'Date'         => gmdate('r'),
        ];

        if (!empty($email['cc'])) {
            $headers['Cc'] = self::joinAddresses($email['cc']);
        }
        if (!empty($email['reply_to'])) {
            $headers['Reply-To'] = (string) $email['reply_to'];
        }
        // bcc is intentionally NOT written to the message body — the
        // transport feeds bcc recipients via the envelope (RCPT TO),
        // not via a header. Including a Bcc: header would defeat its
        // purpose.

        foreach (($email['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . self::foldLongHeader($value);
        }
        $lines[] = '';                                                  // blank line: end of headers
        $lines[] = (string) $email['body'];

        $message = implode("\r\n", $lines);

        // DKIM-sign the wire bytes when the email (or the Mailer's
        // defaults) carry a `dkim` config. The signer prepends a
        // `DKIM-Signature: ...` header to the message.
        if (isset($email['dkim']) && is_array($email['dkim']) && $email['dkim'] !== []) {
            $message = DkimSigner::sign($message, $email['dkim']);
        }

        return $message;
    }

    /**
     * Returns every envelope recipient (To + Cc + Bcc) as a flat list.
     * Used by SMTP transports to drive the RCPT TO commands.
     *
     * @param  array<string,mixed> $email
     * @return list<string>
     */
    public static function envelopeRecipients(array $email): array
    {
        $out = [];
        foreach (['to', 'cc', 'bcc'] as $key) {
            $val = $email[$key] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            foreach ((array) $val as $addr) {
                $out[] = self::extractAddress((string) $addr);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Extracts the bare email address from a "Display Name <addr@x.com>"
     * style string. If no angle brackets are present, returns the input
     * trimmed.
     */
    public static function extractAddress(string $address): string
    {
        if (preg_match('/<([^>]+)>/', $address, $m) === 1) {
            return trim($m[1]);
        }
        return trim($address);
    }

    /**
     * @param string|list<string> $addresses
     */
    private static function joinAddresses(mixed $addresses): string
    {
        if (is_string($addresses)) {
            return $addresses;
        }
        return implode(', ', (array) $addresses);
    }

    /**
     * RFC 2047 encoded-word for headers with non-ASCII content.
     * Ascii values pass through unchanged.
     */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xff]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * RFC 5322 "long header" folding — split lines longer than 78
     * chars onto continuation lines. Important for sendmail and old
     * MTAs that reject overlong header lines.
     */
    private static function foldLongHeader(string $value): string
    {
        if (strlen($value) <= 76) {
            return $value;
        }
        return wordwrap($value, 76, "\r\n ", false);
    }
}
