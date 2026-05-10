<?php

declare(strict_types=1);

namespace App\Domain\Loan;

use App\Domain\Book\Isbn;
use App\Domain\Shared\DomainException;

/**
 * Aggregate root for a single loan: a member borrowing one copy of a book.
 *
 * Holds two value objects (the book's ISBN and the LoanPeriod). The
 * member is modelled as a plain string here to keep the demo focused —
 * promoting `Member` to its own aggregate is the obvious next step in a
 * real system.
 *
 * Lifecycle: open() → close($at). `close()` is idempotent-by-rejection:
 * calling it on an already-closed loan throws.
 */
final class Loan
{
    public function __construct(
        private LoanId     $id,
        private Isbn       $book,
        private string     $memberName,
        private LoanPeriod $period,
    ) {}

    public static function open(Isbn $book, string $memberName): self
    {
        $name = trim($memberName);
        if ($name === '') {
            throw new DomainException('Member name is required to open a loan');
        }
        return new self(LoanId::generate(), $book, $name, LoanPeriod::startingNow());
    }

    public function close(\DateTimeImmutable $at): void
    {
        if ($this->period->isReturned()) {
            throw new DomainException('Loan is already closed');
        }
        $this->period = $this->period->markReturned($at);
    }

    public function id(): LoanId
    {
        return $this->id;
    }
    public function book(): Isbn
    {
        return $this->book;
    }
    public function memberName(): string
    {
        return $this->memberName;
    }
    public function period(): LoanPeriod
    {
        return $this->period;
    }
    public function isActive(): bool
    {
        return !$this->period->isReturned();
    }
}
