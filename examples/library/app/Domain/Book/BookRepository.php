<?php

declare(strict_types=1);

namespace App\Domain\Book;

/**
 * Domain-side contract for book persistence.
 *
 * The interface lives in the domain layer so use cases / domain services
 * can depend on it without learning anything about JSON files, SQL, or
 * HTTP. The concrete adapter lives under `App\Infrastructure`.
 *
 * `save()` is upsert by ISBN: it covers both registering a new book and
 * persisting state changes (e.g. after `borrowOne()`).
 */
interface BookRepository
{
    public function ofIsbn(Isbn $isbn): ?Book;

    public function save(Book $book): void;

    /**
     * @return list<Book>
     */
    public function all(): array;
}
