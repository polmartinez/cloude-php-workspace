<?php

declare(strict_types=1);

namespace App\Domain\Loan;

/**
 * Value object: the borrowed → due → (optionally) returned timeline of a
 * single loan. Immutable; closing a loan returns a new instance via
 * `markReturned()` rather than mutating in place.
 *
 * The "is overdue?" rule lives here, next to the dates it operates on,
 * instead of being scattered across services.
 */
final class LoanPeriod
{
    public function __construct(
        private \DateTimeImmutable  $borrowedAt,
        private \DateTimeImmutable  $dueAt,
        private ?\DateTimeImmutable $returnedAt = null,
    ) {
        if ($dueAt < $borrowedAt) {
            throw new \InvalidArgumentException('dueAt cannot be earlier than borrowedAt');
        }
        if ($returnedAt !== null && $returnedAt < $borrowedAt) {
            throw new \InvalidArgumentException('returnedAt cannot be earlier than borrowedAt');
        }
    }

    public static function startingNow(int $days = 14): self
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return new self($now, $now->modify("+{$days} days"));
    }

    public function markReturned(\DateTimeImmutable $at): self
    {
        return new self($this->borrowedAt, $this->dueAt, $at);
    }

    public function isReturned(): bool
    {
        return $this->returnedAt !== null;
    }

    public function isOverdue(?\DateTimeImmutable $now = null): bool
    {
        if ($this->isReturned()) {
            return false;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $now > $this->dueAt;
    }

    public function borrowedAt(): \DateTimeImmutable  { return $this->borrowedAt; }
    public function dueAt():      \DateTimeImmutable  { return $this->dueAt; }
    public function returnedAt(): ?\DateTimeImmutable { return $this->returnedAt; }
}
