<?php

declare(strict_types=1);

namespace Cloude\Tests\Mail;

use Cloude\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Unit-testing a full SMTP exchange requires a real (or fake) server,
 * which is out of scope for this suite. Here we cover the protocol
 * code paths that DON'T need a network round-trip:
 *
 *   - Connect failure surfaces as RuntimeException with host/port info
 *   - The class instantiates cleanly with realistic config shapes
 *
 * For an integration test you'd point this at a local mailhog or
 * smtp-sink container — out of scope here.
 */
final class SmtpTransportTest extends TestCase
{
    public function testConnectFailureThrowsWithContext(): void
    {
        // Port 1 is the "tcpmux" reserved port; reliably refused
        // everywhere. fsockopen returns false → RuntimeException.
        $t = new SmtpTransport('127.0.0.1', 1, timeout: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP connect to 127.0.0.1:1 failed');
        @$t->send([
            'to' => 'a@x.com', 'from' => 's@x.com',
            'subject' => 'X', 'body' => 'x',
        ]);
    }

    public function testConstructorAcceptsRealisticConfig(): void
    {
        // Doesn't actually connect — just verifies the type contract.
        $t = new SmtpTransport(
            host:    'smtp.example.com',
            port:    587,
            user:    'app',
            pass:    's3cret',
            tls:     true,
            timeout: 10,
        );
        self::assertInstanceOf(SmtpTransport::class, $t);
    }
}
