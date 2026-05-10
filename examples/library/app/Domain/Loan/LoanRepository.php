<?php

declare(strict_types=1);

namespace App\Domain\Loan;

interface LoanRepository
{
    public function ofId(LoanId $id): ?Loan;

    public function save(Loan $loan): void;

    /**
     * @return list<Loan>
     */
    public function all(): array;

    /**
     * @return list<Loan>
     */
    public function active(): array;
}
