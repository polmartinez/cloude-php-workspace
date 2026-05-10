<?php

declare(strict_types=1);

namespace App\Domain\Loan;

use App\Domain\Shared\DomainException;

/**
 * Value object: a UUID-shaped loan identifier.
 *
 * The domain layer mints its own IDs (instead of asking the repository
 * for a database-assigned key) so a `Loan` is a complete, valid
 * aggregate from the moment it's constructed. UUID generation uses
 * plain `random_bytes` to keep the domain free of framework imports.
 */
final class LoanId implements \Stringable
{
    private function __construct(private string $value) {}

    public static function generate(): self
    {
        // RFC 4122 v4 (random) — same scheme as Cloude\Str::uuid, written
        // out here so the domain layer has no infrastructure dependencies.
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)));
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new DomainException("Invalid LoanId: '$value'");
        }
        return new self($value);
    }

    public function value(): string                  { return $this->value; }
    public function equals(self $other): bool        { return $this->value === $other->value; }
    public function __toString(): string             { return $this->value; }
}
