<?php

declare(strict_types=1);

namespace App\Domain\Book;

use App\Domain\Shared\DomainException;

/**
 * Value object: an ISBN-10 or ISBN-13.
 *
 * Self-validating in the constructor — once you hold an Isbn instance, the
 * value is guaranteed to be a sequence of 10 or 13 digits. Whitespace and
 * dashes in the input are accepted and stripped (so "978-0-321-12521-7"
 * normalises to "9780321125217").
 *
 * Value objects are immutable and compared by value, never by reference —
 * see equals().
 */
final class Isbn implements \Stringable
{
    private string $value;

    public function __construct(string $raw)
    {
        $digits = (string) preg_replace('/[\s-]/', '', $raw);
        if (preg_match('/^\d{10}$|^\d{13}$/', $digits) !== 1) {
            throw new DomainException("Invalid ISBN: '$raw' (expected 10 or 13 digits)");
        }
        $this->value = $digits;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
