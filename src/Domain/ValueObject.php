<?php

declare(strict_types=1);

namespace Cloude\Domain;

/**
 * Optional base for value objects in DDD-shaped projects. Carries two
 * conventions everyone re-implements otherwise:
 *
 *   1. **Structural equality** — two value objects are equal when they
 *      have the same concrete class AND every property compares equal.
 *      No identity. Override `equals()` only if a property needs
 *      special comparison (e.g. case-insensitive strings).
 *
 *   2. **Stringable** — subclasses provide a single canonical textual
 *      rendering. Lets you log / format / concat without ceremony.
 *
 * Subclass with `readonly` properties to lock immutability:
 *
 *   final class Money extends ValueObject
 *   {
 *       public function __construct(
 *           public readonly int $amount,        // cents
 *           public readonly string $currency,
 *       ) {
 *           if ($amount < 0) {
 *               throw new DomainException('Money cannot be negative');
 *           }
 *       }
 *
 *       public function __toString(): string
 *       {
 *           return number_format($this->amount / 100, 2) . ' ' . $this->currency;
 *       }
 *   }
 *
 *   $a = new Money(1250, 'EUR');
 *   $b = new Money(1250, 'EUR');
 *   $a->equals($b);     // true — same class, same field values
 *   (string) $a;        // '12.50 EUR'
 *
 * The framework imposes no extra interfaces; this is a convenience, not
 * a contract. If a project prefers plain final classes without a base
 * class, that works too.
 */
abstract class ValueObject implements \Stringable
{
    /**
     * Structural equality: same concrete class AND every public/readonly
     * property compares equal. Override for case-insensitive or
     * canonicalised comparisons.
     */
    public function equals(self $other): bool
    {
        if (static::class !== $other::class) {
            return false;
        }
        return get_object_vars($this) === get_object_vars($other);
    }

    /** Subclasses define their canonical textual representation. */
    abstract public function __toString(): string;
}
