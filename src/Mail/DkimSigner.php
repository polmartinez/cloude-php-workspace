<?php

declare(strict_types=1);

namespace Cloude\Mail;

/**
 * RFC 6376 DKIM signer with `relaxed/relaxed` canonicalization and
 * RSA-SHA256. Computes a body hash, builds a `DKIM-Signature:` header,
 * RSA-signs the canonical header block, and prepends the completed
 * signature header to the message.
 *
 * Designed to be the smallest credible implementation — no key
 * rotation, no ed25519, no domain-based selector discovery. The DNS
 * side (publishing `selector._domainkey.domain.com TXT` with the
 * matching public key) is the operator's responsibility.
 *
 * ## Config shape
 *
 *   'dkim' => [
 *       'domain'      => 'example.com',
 *       'selector'    => 'default',                    // → default._domainkey.example.com
 *       'private_key' => '/etc/dkim/private.pem',      // path
 *       // or:        => 'env:DKIM_PRIVATE_KEY'        // env var holding the PEM
 *       // or:        => "-----BEGIN PRIVATE KEY...",  // inline PEM
 *       'passphrase'  => null,                          // optional
 *       'headers'     => ['From', 'To', 'Subject', 'Date', 'MIME-Version', 'Content-Type'],
 *   ],
 *
 * ## Usage
 *
 *   $signed = DkimSigner::sign($rfc5322Message, $dkimConfig);
 *
 * `Cloude\Mail\Message::build()` calls this transparently when the
 * email array carries a `'dkim'` key, so you usually never call
 * `sign()` directly — set the config in `app/config/email.php` and
 * every send is signed.
 *
 * ## Verifying locally
 *
 * Pair the private key with its public key in DNS:
 *
 *   selector._domainkey.example.com   IN TXT
 *     "v=DKIM1; k=rsa; p=<base64 public key>"
 *
 * Then a receiver can verify with `openssl dgst -verify ...` or any
 * MTA's DKIM-verifier.
 */
final class DkimSigner
{
    /**
     * Sign an RFC 5322 message. Returns a new message string with a
     * `DKIM-Signature:` header prepended at the very top.
     *
     * @param array<string,mixed> $config See class docblock for the shape.
     */
    public static function sign(string $message, array $config): string
    {
        $domain   = self::required($config, 'domain');
        $selector = self::required($config, 'selector');
        $keySrc   = self::required($config, 'private_key');
        $passphrase    = $config['passphrase'] ?? null;
        $headersToSign = $config['headers'] ?? [
            'From', 'To', 'Subject', 'Date', 'MIME-Version', 'Content-Type',
        ];

        $keyPem = self::loadKey($keySrc);
        $key    = openssl_pkey_get_private($keyPem, $passphrase ?? '');
        if ($key === false) {
            throw new \InvalidArgumentException(
                'DKIM: cannot load private key (bad PEM, wrong passphrase, '
                . 'or unreadable file). openssl error: ' . openssl_error_string(),
            );
        }

        [$rawHeaders, $body] = self::splitMessage($message);
        $headerMap = self::parseHeaders($rawHeaders);

        // bh= : body hash over the relaxed-canonical body.
        $bodyHash = base64_encode(hash('sha256', self::canonBody($body), true));

        // The signed-headers list is the intersection of $headersToSign and
        // the headers actually present, preserving the configured order.
        $signedNames  = [];
        $canonHeaders = '';
        foreach ($headersToSign as $name) {
            $lc = strtolower($name);
            if (!isset($headerMap[$lc])) {
                continue;
            }
            $signedNames[]   = $name;
            $canonHeaders   .= self::canonHeader($lc, $headerMap[$lc]) . "\r\n";
        }
        if ($signedNames === []) {
            throw new \InvalidArgumentException(
                'DKIM: none of the configured headers are present in the message',
            );
        }

        // Build the DKIM-Signature header with an empty `b=` field, then
        // canonicalize and sign the resulting block.
        $dkimBody = 'v=1; a=rsa-sha256; c=relaxed/relaxed'
            . '; d=' . $domain
            . '; s=' . $selector
            . '; t=' . time()
            . '; h=' . implode(':', $signedNames)
            . '; bh=' . $bodyHash
            . '; b=';
        $canonDkim = self::canonHeader('dkim-signature', $dkimBody);
        // The signed block ends WITHOUT trailing CRLF on the DKIM line (RFC 6376 §3.7).
        $toSign = $canonHeaders . $canonDkim;

        if (!openssl_sign($toSign, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException(
                'DKIM: openssl_sign failed — ' . openssl_error_string(),
            );
        }

        $sigB64       = base64_encode($signature);
        $finalDkim    = 'DKIM-Signature: ' . $dkimBody . $sigB64;
        return $finalDkim . "\r\n" . $message;
    }

    /**
     * Reads the private key according to the source-string convention:
     *
     *   - `-----BEGIN ...`         — inline PEM
     *   - `env:VAR_NAME`           — env var holding the PEM
     *   - `file:///path/to/key`    — explicit file URL
     *   - anything else            — treated as a filesystem path
     */
    private static function loadKey(string $src): string
    {
        if (str_starts_with($src, '-----BEGIN')) {
            return $src;
        }
        if (str_starts_with($src, 'env:')) {
            $val = getenv(substr($src, 4));
            if ($val === false || $val === '') {
                throw new \InvalidArgumentException(
                    "DKIM: env var '" . substr($src, 4) . "' is empty or unset",
                );
            }
            return $val;
        }
        $path = str_starts_with($src, 'file://') ? substr($src, 7) : $src;
        if (!is_file($path)) {
            throw new \InvalidArgumentException("DKIM: private key file not found at '$path'");
        }
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new \InvalidArgumentException("DKIM: private key file '$path' is unreadable or empty");
        }
        return $contents;
    }

    /**
     * @return array{0:string, 1:string}
     */
    private static function splitMessage(string $msg): array
    {
        $pos = strpos($msg, "\r\n\r\n");
        if ($pos !== false) {
            return [substr($msg, 0, $pos), substr($msg, $pos + 4)];
        }
        $pos = strpos($msg, "\n\n");
        if ($pos !== false) {
            return [substr($msg, 0, $pos), substr($msg, $pos + 2)];
        }
        return [$msg, ''];
    }

    /**
     * Parse the raw header block into a `lowercased-name => value` map.
     * Unfolds continuation lines per RFC 5322.
     *
     * @return array<string,string>
     */
    private static function parseHeaders(string $raw): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $raw) ?? $raw;
        $headers  = [];
        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = ltrim($value);
        }
        return $headers;
    }

    /**
     * Relaxed-header canonicalization per RFC 6376 §3.4.2:
     *   - lowercased name
     *   - collapse internal WSP to single SP
     *   - trim leading / trailing WSP from the value
     *   - format as `name:value` (no space after the colon)
     */
    private static function canonHeader(string $name, string $value): string
    {
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        $value = trim($value);
        return strtolower($name) . ':' . $value;
    }

    /**
     * Relaxed-body canonicalization per RFC 6376 §3.4.4:
     *   - normalise CRLF
     *   - collapse internal WSP to single SP
     *   - strip trailing WSP from each line
     *   - strip trailing empty lines (keep exactly one CRLF when body
     *     is non-empty)
     */
    private static function canonBody(string $body): string
    {
        $body = preg_replace('/\r?\n/', "\r\n", $body) ?? $body;
        $body = preg_replace('/[ \t]+/', ' ', $body) ?? $body;
        $body = preg_replace('/[ \t]+\r\n/', "\r\n", $body) ?? $body;
        $body = rtrim($body, "\r\n");
        return $body === '' ? '' : $body . "\r\n";
    }

    private static function required(array $config, string $key): string
    {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new \InvalidArgumentException("DKIM config is missing required key '$key'");
        }
        return (string) $config[$key];
    }
}
