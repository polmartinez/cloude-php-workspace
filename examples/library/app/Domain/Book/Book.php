<?php

declare(strict_types=1);

namespace App\Domain\Book;

use App\Domain\Shared\DomainException;

/**
 * Aggregate root for a catalogued book.
 *
 * Identity is the ISBN (a value object). The aggregate owns one invariant
 * worth enforcing: `0 <= copiesAvailable <= copiesTotal`. Borrow / return
 * mutate the aggregate atomically through `borrowOne()` / `returnOne()`,
 * which throw before letting state drift out of range.
 *
 * The constructor is intentionally usable two ways:
 *   - Book::register(...)  for "fresh" books — copies start at full stock.
 *   - new Book(...)        for hydration from persistence (the repository
 *     already trusts the on-disk state, so we don't reset stock).
 */
final class Book
{
    public function __construct(
        private Isbn   $isbn,
        private string $title,
        private string $author,
        private int    $copiesTotal,
        private int    $copiesAvailable,
    ) {
        if ($title === '' || $author === '') {
            throw new DomainException('Book needs both a title and an author');
        }
        if ($copiesTotal < 1) {
            throw new DomainException('A book must have at least one copy');
        }
        if ($copiesAvailable < 0 || $copiesAvailable > $copiesTotal) {
            throw new DomainException(
                "copiesAvailable ($copiesAvailable) must be between 0 and copiesTotal ($copiesTotal)",
            );
        }
    }

    /**
     * Named constructor for adding a brand-new book to the catalogue. All
     * copies start as available.
     */
    public static function register(Isbn $isbn, string $title, string $author, int $copies): self
    {
        return new self($isbn, $title, $author, $copies, $copies);
    }

    /**
     * Decrement available stock by one. Throws when no copies are left —
     * this is the invariant that protects every borrow flow downstream.
     */
    public function borrowOne(): void
    {
        if ($this->copiesAvailable === 0) {
            throw new DomainException("No copies available for \"$this->title\"");
        }
        $this->copiesAvailable--;
    }

    public function returnOne(): void
    {
        if ($this->copiesAvailable >= $this->copiesTotal) {
            throw new DomainException("All copies of \"$this->title\" are already on the shelf");
        }
        $this->copiesAvailable++;
    }

    public function isAvailable(): bool      { return $this->copiesAvailable > 0; }
    public function isbn():            Isbn   { return $this->isbn; }
    public function title():           string { return $this->title; }
    public function author():          string { return $this->author; }
    public function copiesTotal():     int    { return $this->copiesTotal; }
    public function copiesAvailable(): int    { return $this->copiesAvailable; }
}
