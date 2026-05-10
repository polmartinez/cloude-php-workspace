<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Book\Isbn;
use App\Domain\Loan\Loan;
use App\Domain\Service\BorrowingService;

final class BorrowBook
{
    public function __construct(private BorrowingService $borrowing) {}

    public function __invoke(string $isbn, string $memberName): Loan
    {
        return $this->borrowing->borrow(new Isbn($isbn), $memberName);
    }
}
