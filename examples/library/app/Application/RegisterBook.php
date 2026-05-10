<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Book\Book;
use App\Domain\Book\BookRepository;
use App\Domain\Book\Isbn;

/**
 * Use case: register a new book in the catalogue.
 *
 * Application services are intentionally thin. They:
 *   - translate primitives (raw ISBN string, plain ints) into value objects,
 *   - delegate the actual rule-keeping to the domain (Book::register),
 *   - persist through a repository interface.
 *
 * `__invoke` makes this class callable, so route handlers can treat the
 * use case as a callable: `($this->register)($isbn, $title, $author, $copies)`.
 */
final class RegisterBook
{
    public function __construct(private BookRepository $books) {}

    public function __invoke(string $isbn, string $title, string $author, int $copies): Book
    {
        $book = Book::register(new Isbn($isbn), $title, $author, $copies);
        $this->books->save($book);
        return $book;
    }
}
