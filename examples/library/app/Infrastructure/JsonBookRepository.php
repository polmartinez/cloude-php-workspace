<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Book\Book;
use App\Domain\Book\BookRepository;
use App\Domain\Book\Isbn;
use Cloude\JsonFile;

/**
 * Cloude-backed adapter for the BookRepository contract.
 *
 * One `.json` file per book, named after the ISBN. Atomic writes
 * (temp + rename) and the per-request read cache come from
 * `Cloude\JsonFile` — this class only worries about hydration: turning
 * the on-disk row back into a Book aggregate.
 */
final class JsonBookRepository implements BookRepository
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public function ofIsbn(Isbn $isbn): ?Book
    {
        $data = JsonFile::read($this->path($isbn->value()));
        return $data === null ? null : self::hydrate($data);
    }

    public function save(Book $book): void
    {
        JsonFile::write(
            $this->path($book->isbn()->value()),
            [
                'isbn'             => $book->isbn()->value(),
                'title'            => $book->title(),
                'author'           => $book->author(),
                'copies_total'     => $book->copiesTotal(),
                'copies_available' => $book->copiesAvailable(),
            ],
            pretty: true,
        );
    }

    public function all(): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        $books = [];
        foreach ($files as $f) {
            $data = JsonFile::read($f);
            if ($data !== null) {
                $books[] = self::hydrate($data);
            }
        }
        return $books;
    }

    private function path(string $isbn): string
    {
        return $this->dir . '/' . $isbn . '.json';
    }

    /**
     * @param array<mixed> $data
     */
    private static function hydrate(array $data): Book
    {
        return new Book(
            new Isbn((string) $data['isbn']),
            (string) $data['title'],
            (string) $data['author'],
            (int)    $data['copies_total'],
            (int)    $data['copies_available'],
        );
    }
}
