<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Book\BookRepository;
use App\Domain\Book\Isbn;
use App\Domain\Loan\Loan;
use App\Domain\Loan\LoanId;
use App\Domain\Loan\LoanRepository;
use App\Domain\Shared\DomainException;

/**
 * Domain service that owns the consistency boundary across Book + Loan.
 *
 * "Borrow" and "return" are operations that mutate two aggregates
 * (`Book.copiesAvailable` and a `Loan`). When a single behaviour spans
 * aggregates and isn't a natural method of either one, DDD puts it in a
 * domain service. That's this class.
 *
 * The service depends only on repository *interfaces* (`BookRepository`,
 * `LoanRepository`), so domain logic stays decoupled from JSON files,
 * SQL or whatever the application happens to use today.
 */
final class BorrowingService
{
    public function __construct(
        private BookRepository $books,
        private LoanRepository $loans,
    ) {}

    /**
     * Open a loan for `$memberName` against the book with `$isbn`.
     *
     * Atomic from the domain's point of view: if Book.borrowOne() throws
     * (e.g. no copies left), no Loan is created and nothing is persisted.
     */
    public function borrow(Isbn $isbn, string $memberName): Loan
    {
        $book = $this->books->ofIsbn($isbn);
        if ($book === null) {
            throw new DomainException("Unknown book: $isbn");
        }

        $book->borrowOne();              // throws if no copies — invariant guarded
        $loan = Loan::open($isbn, $memberName);

        $this->books->save($book);
        $this->loans->save($loan);

        return $loan;
    }

    /**
     * Close the loan with `$loanId` and put the copy back on the shelf.
     */
    public function returnBook(LoanId $loanId): void
    {
        $loan = $this->loans->ofId($loanId);
        if ($loan === null) {
            throw new DomainException("Loan not found: $loanId");
        }
        $book = $this->books->ofIsbn($loan->book());
        if ($book === null) {
            throw new DomainException("Book has vanished from the catalogue: {$loan->book()}");
        }

        $loan->close(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $book->returnOne();

        $this->books->save($book);
        $this->loans->save($loan);
    }
}
