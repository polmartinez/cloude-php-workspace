<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Loan\LoanId;
use App\Domain\Service\BorrowingService;

final class ReturnBook
{
    public function __construct(private BorrowingService $borrowing) {}

    public function __invoke(string $loanId): void
    {
        $this->borrowing->returnBook(LoanId::fromString($loanId));
    }
}
