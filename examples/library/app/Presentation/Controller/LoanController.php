<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\ReturnBook;
use App\Domain\Book\BookRepository;
use App\Domain\Loan\LoanRepository;
use App\Domain\Shared\DomainException;
use Cloude\Http\Response;
use Cloude\View;

class LoanController
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private ReturnBook     $return,
    ) {}

    public function index(): void
    {
        // Build display rows by joining loan + book in the controller. Keeping
        // this in the presentation layer (instead of the domain) avoids
        // dragging UI shapes back into the model.
        $rows = [];
        foreach ($this->loans->active() as $loan) {
            $book = $this->books->ofIsbn($loan->book());
            $rows[] = [
                'id'      => $loan->id()->value(),
                'title'   => $book?->title() ?? "(missing book {$loan->book()})",
                'isbn'    => $loan->book()->value(),
                'member'  => $loan->memberName(),
                'due_at'  => $loan->period()->dueAt()->format('Y-m-d'),
                'overdue' => $loan->period()->isOverdue(),
            ];
        }
        usort($rows, static fn ($a, $b) => $a['due_at'] <=> $b['due_at']);

        View::render('layout.php', [
            'title'   => 'Active loans',
            'content' => 'loans.php',
            'loans'   => $rows,
            'flash'   => null,
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function return(array $params): void
    {
        try {
            ($this->return)($params['id']);
        } catch (DomainException $e) {
            Response::redirect(BASE_URL . '/loans?error=' . rawurlencode($e->getMessage()), 303);
            return;
        }
        Response::redirect(BASE_URL . '/?returned=1', 303);
    }
}
