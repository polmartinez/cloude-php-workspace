<?php

declare(strict_types=1);

namespace Cloude\Mail\Transport;

use Cloude\Mail\Message;
use Cloude\Mail\Transport;

/**
 * In-memory transport for tests. Collects every "sent" message into
 * an array so the test can assert on what would have gone out.
 *
 * Mirrors `Cloude\Model\Storage\ArrayStorage` — same idea, different
 * domain. Use in `setUp()`:
 *
 *   $transport = new MemoryTransport();
 *   $mailer    = new Mailer($transport);
 *   // ... exercise code ...
 *   self::assertCount(1, $transport->sent());
 *   self::assertSame('Welcome', $transport->sent()[0]['subject']);
 */
final class MemoryTransport implements Transport
{
    /** @var list<array<string,mixed>> */
    private array $sent = [];

    public function send(array $email): bool
    {
        // Snapshot what the wire format WOULD have been so tests can
        // assert on header folding / encoding without re-building it.
        $email['_rendered'] = Message::build($email);
        $this->sent[] = $email;
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
