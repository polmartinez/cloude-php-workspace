<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Book\Isbn;
use App\Domain\Loan\Loan;
use App\Domain\Loan\LoanId;
use App\Domain\Loan\LoanPeriod;
use App\Domain\Loan\LoanRepository;
use Cloude\JsonFile;

final class JsonLoanRepository implements LoanRepository
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public function ofId(LoanId $id): ?Loan
    {
        $data = JsonFile::read($this->path($id->value()));
        return $data === null ? null : self::hydrate($data);
    }

    public function save(Loan $loan): void
    {
        $period = $loan->period();
        JsonFile::write(
            $this->path($loan->id()->value()),
            [
                'id'           => $loan->id()->value(),
                'book_isbn'    => $loan->book()->value(),
                'member_name'  => $loan->memberName(),
                'borrowed_at'  => $period->borrowedAt()->format(\DateTimeInterface::ATOM),
                'due_at'       => $period->dueAt()->format(\DateTimeInterface::ATOM),
                'returned_at'  => $period->returnedAt()?->format(\DateTimeInterface::ATOM),
            ],
            pretty: true,
        );
    }

    public function all(): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        $loans = [];
        foreach ($files as $f) {
            $data = JsonFile::read($f);
            if ($data !== null) {
                $loans[] = self::hydrate($data);
            }
        }
        return $loans;
    }

    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (Loan $l) => $l->isActive()));
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    /**
     * @param array<mixed> $data
     */
    private static function hydrate(array $data): Loan
    {
        $utc = new \DateTimeZone('UTC');
        $period = new LoanPeriod(
            new \DateTimeImmutable((string) $data['borrowed_at'], $utc),
            new \DateTimeImmutable((string) $data['due_at'], $utc),
            !empty($data['returned_at'])
                ? new \DateTimeImmutable((string) $data['returned_at'], $utc)
                : null,
        );
        return new Loan(
            LoanId::fromString((string) $data['id']),
            new Isbn((string) $data['book_isbn']),
            (string) $data['member_name'],
            $period,
        );
    }
}
