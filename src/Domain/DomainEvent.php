<?php

declare(strict_types=1);

namespace Cloude\Domain;

/**
 * Marker interface for things that happen inside the domain and that
 * the rest of the system might care about (`BookBorrowed`,
 * `OrderShipped`, `UserRegistered`, …). One event per fact.
 *
 * The framework intentionally ships no event bus / dispatcher — the
 * application layer pulls events off the aggregate after persistence
 * and decides what to do with them (log, queue, fan out). Keeping the
 * dispatch policy outside the domain stays true to the "no magic" rule.
 *
 *   final class BookBorrowed implements \Cloude\Domain\DomainEvent
 *   {
 *       public function __construct(
 *           public readonly string $bookId,
 *           public readonly string $memberId,
 *           public readonly \DateTimeImmutable $when,
 *       ) {}
 *
 *       public function occurredOn(): \DateTimeImmutable
 *       {
 *           return $this->when;
 *       }
 *   }
 */
interface DomainEvent
{
    public function occurredOn(): \DateTimeImmutable;
}
