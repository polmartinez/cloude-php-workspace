<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\BorrowBook;
use App\Application\RegisterBook;
use App\Domain\Book\BookRepository;
use App\Domain\Shared\DomainException;
use Cloude\Http\Response;
use Cloude\Input;
use Cloude\View;

/**
 * HTTP entry-point for the book endpoints.
 *
 * Pure boundary code: parses the request, calls a use case or repository,
 * formats the response. Domain exceptions become flash messages or
 * inline form errors; nothing here knows about copies, ISBNs, or the
 * borrow rules — that's the domain's job.
 */
class BookController
{
    public function __construct(
        private BookRepository $books,
        private RegisterBook   $register,
        private BorrowBook     $borrow,
    ) {}

    public function index(): void
    {
        $books = $this->books->all();
        usort($books, static fn ($a, $b) => $a->title() <=> $b->title());

        View::render('layout.html.php', [
            'title'   => 'Catalogue',
            'content' => 'books.html.php',
            'books'   => $books,
            'flash'   => self::flash(),
        ]);
    }

    public function apiList(): void
    {
        $books = $this->books->all();
        Response::json([
            'count' => count($books),
            'books' => array_map(static fn ($b) => [
                'isbn'             => $b->isbn()->value(),
                'title'            => $b->title(),
                'author'           => $b->author(),
                'copies_total'     => $b->copiesTotal(),
                'copies_available' => $b->copiesAvailable(),
                'available'        => $b->isAvailable(),
            ], $books),
        ]);
    }

    public function newForm(): void
    {
        View::render('layout.html.php', [
            'title'   => 'Register a book',
            'content' => 'book_form.html.php',
            'errors'  => [],
            'old'     => ['isbn' => '', 'title' => '', 'author' => '', 'copies' => '1'],
        ]);
    }

    public function create(): void
    {
        $input = [
            'isbn'   => trim((string) Input::post('isbn', '')),
            'title'  => trim((string) Input::post('title', '')),
            'author' => trim((string) Input::post('author', '')),
            'copies' => max(1, (int) Input::post('copies', 1)),
        ];

        try {
            ($this->register)($input['isbn'], $input['title'], $input['author'], $input['copies']);
        } catch (DomainException $e) {
            http_response_code(422);
            View::render('layout.html.php', [
                'title'   => 'Register a book',
                'content' => 'book_form.html.php',
                'errors'  => [$e->getMessage()],
                'old'     => $input,
            ]);
            return;
        }

        Response::redirect(BASE_URL . '/?registered=1', 303);
    }

    /**
     * @param array<string,string> $params
     */
    public function borrow(array $params): void
    {
        $member = trim((string) Input::post('member', ''));
        try {
            ($this->borrow)($params['isbn'], $member);
        } catch (DomainException $e) {
            Response::redirect(BASE_URL . '/?error=' . rawurlencode($e->getMessage()), 303);
            return;
        }
        Response::redirect(BASE_URL . '/?borrowed=1', 303);
    }

    private static function flash(): ?string
    {
        return match (true) {
            Input::get('registered') !== null => 'Book registered.',
            Input::get('borrowed')   !== null => 'Book borrowed.',
            Input::get('returned')   !== null => 'Book returned.',
            Input::get('error')      !== null => 'Error: ' . (string) Input::get('error'),
            default                           => null,
        };
    }
}
