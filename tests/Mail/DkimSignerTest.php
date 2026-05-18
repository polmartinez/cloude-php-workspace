<?php

declare(strict_types=1);

namespace Cloude\Tests\Mail;

use Cloude\Mail\DkimSigner;
use Cloude\Mail\Message;
use Cloude\Testing\TestCase;

final class DkimSignerTest extends TestCase
{
    private string $privateKey;
    private string $publicKey;

    protected function setUp(): void
    {
        // Generate a fresh RSA-2048 keypair for each test run. Tests stay
        // hermetic (no fixture key in the repo) and the signer's full
        // sign→verify loop is exercised against real OpenSSL.
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($res, 'failed to generate RSA keypair');
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        $this->privateKey = $privatePem;
        $this->publicKey  = $details['key'];
    }

    public function testSignPrependsDkimHeader(): void
    {
        $msg = $this->sampleMessage();
        $signed = DkimSigner::sign($msg, [
            'domain'      => 'example.com',
            'selector'    => 'default',
            'private_key' => $this->privateKey,
        ]);

        self::assertStringStartsWith('DKIM-Signature: ', $signed);
        // Original message body must be intact.
        self::assertStringContainsString('Subject: Hello', $signed);
        self::assertStringContainsString('Hello world.', $signed);
    }

    public function testSignedHeaderContainsExpectedTags(): void
    {
        $signed = DkimSigner::sign($this->sampleMessage(), [
            'domain'      => 'example.com',
            'selector'    => 'mail',
            'private_key' => $this->privateKey,
        ]);

        // First line is the DKIM-Signature header.
        $dkimLine = strstr($signed, "\r\n", true);
        self::assertNotFalse($dkimLine);

        self::assertStringContainsString('v=1', $dkimLine);
        self::assertStringContainsString('a=rsa-sha256', $dkimLine);
        self::assertStringContainsString('c=relaxed/relaxed', $dkimLine);
        self::assertStringContainsString('d=example.com', $dkimLine);
        self::assertStringContainsString('s=mail', $dkimLine);
        self::assertStringContainsString('h=', $dkimLine);
        self::assertStringContainsString('bh=', $dkimLine);
        self::assertStringContainsString('b=', $dkimLine);
    }

    public function testSignatureVerifiesAgainstThePublicKey(): void
    {
        // Round-trip: rebuild the signed-block exactly the way the signer
        // did and verify the base64 b= signature with the matching pubkey.
        // This is the same logic an MTA-side DKIM verifier follows.
        $signed = DkimSigner::sign($this->sampleMessage(), [
            'domain'      => 'example.com',
            'selector'    => 'default',
            'private_key' => $this->privateKey,
        ]);

        // Split off the prepended DKIM-Signature: header.
        [$dkimHeader, $rest] = explode("\r\n", $signed, 2);
        // Reconstruct the canonical block that was signed:
        // 1. Canonicalize the signed headers from $rest (in the order
        //    listed in h=).
        $headers = $this->parseHeaders(strstr($rest, "\r\n\r\n", true) ?: $rest);

        // Pull tag values from the DKIM-Signature header.
        $tags = $this->parseDkimTags(substr($dkimHeader, strlen('DKIM-Signature: ')));
        $signedNames = explode(':', $tags['h']);
        $canonHeaders = '';
        foreach ($signedNames as $name) {
            $canonHeaders .= $this->canonHeader($name, $headers[strtolower($name)]) . "\r\n";
        }
        // 2. Canonicalize the DKIM-Signature header itself with b= empty.
        $dkimBody = preg_replace('/b=[A-Za-z0-9+\/=]+\s*$/', 'b=', substr($dkimHeader, strlen('DKIM-Signature: ')));
        $canonDkim = $this->canonHeader('DKIM-Signature', $dkimBody);

        $toVerify = $canonHeaders . $canonDkim;
        $signature = base64_decode($tags['b'], true);
        self::assertNotFalse($signature, 'b= is not valid base64');

        $ok = openssl_verify($toVerify, $signature, $this->publicKey, OPENSSL_ALGO_SHA256);
        self::assertSame(1, $ok, 'DKIM signature failed openssl_verify');
    }

    public function testMessageBuildSignsWhenDkimKeyPresent(): void
    {
        // End-to-end through the public Mailer surface: passing a 'dkim'
        // key in the email array causes Message::build to sign.
        $built = Message::build([
            'to'      => 'ada@example.com',
            'from'    => 'noreply@example.com',
            'subject' => 'Hi',
            'body'    => 'Hello.',
            'dkim'    => [
                'domain'      => 'example.com',
                'selector'    => 'default',
                'private_key' => $this->privateKey,
            ],
        ]);
        self::assertStringStartsWith('DKIM-Signature: ', $built);
        self::assertStringContainsString('Subject: Hi', $built);
    }

    public function testMissingDomainThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DkimSigner::sign($this->sampleMessage(), [
            'selector'    => 'default',
            'private_key' => $this->privateKey,
        ]);
    }

    public function testMissingPrivateKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DkimSigner::sign($this->sampleMessage(), [
            'domain'   => 'example.com',
            'selector' => 'default',
        ]);
    }

    public function testLoadKeyFromFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dkim_');
        file_put_contents($path, $this->privateKey);
        try {
            $signed = DkimSigner::sign($this->sampleMessage(), [
                'domain'      => 'example.com',
                'selector'    => 'default',
                'private_key' => $path,
            ]);
            self::assertStringStartsWith('DKIM-Signature: ', $signed);
        } finally {
            @unlink($path);
        }
    }

    public function testLoadKeyFromEnv(): void
    {
        putenv('DKIM_TEST_KEY=' . $this->privateKey);
        try {
            $signed = DkimSigner::sign($this->sampleMessage(), [
                'domain'      => 'example.com',
                'selector'    => 'default',
                'private_key' => 'env:DKIM_TEST_KEY',
            ]);
            self::assertStringStartsWith('DKIM-Signature: ', $signed);
        } finally {
            putenv('DKIM_TEST_KEY');
        }
    }

    public function testInvalidKeyFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DkimSigner::sign($this->sampleMessage(), [
            'domain'      => 'example.com',
            'selector'    => 'default',
            'private_key' => '/definitely/not/a/file.pem',
        ]);
    }

    // ── helpers (mirror the DkimSigner's canonicalization for the
    //    verify-side round-trip) ───────────────────────────────────────────

    private function sampleMessage(): string
    {
        return implode("\r\n", [
            'To: ada@example.com',
            'From: noreply@example.com',
            'Subject: Hello',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Date: Mon, 18 May 2026 12:00:00 +0000',
            '',
            'Hello world.',
        ]);
    }

    /** @return array<string,string> */
    private function parseHeaders(string $raw): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $raw) ?? $raw;
        $headers = [];
        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$n, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($n))] = ltrim($v);
        }
        return $headers;
    }

    /** @return array<string,string> */
    private function parseDkimTags(string $body): array
    {
        $tags = [];
        foreach (explode(';', $body) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $tags[trim($k)] = trim($v);
        }
        return $tags;
    }

    private function canonHeader(string $name, string $value): string
    {
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        return strtolower($name) . ':' . trim($value);
    }
}
