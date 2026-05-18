<?php

declare(strict_types=1);

namespace Cloude\Tests\Domain;

use Cloude\Domain\AggregateRoot;
use Cloude\Domain\DomainEvent;
use Cloude\Domain\DomainException;
use PHPUnit\Framework\TestCase;

final class BookBorrowed implements DomainEvent
{
    public function __construct(
        public readonly string $bookId,
        public readonly string $memberId,
        public readonly \DateTimeImmutable $when,
    ) {}

    public function occurredOn(): \DateTimeImmutable
    {
        return $this->when;
    }
}

final class Book extends AggregateRoot
{
    public function __construct(
        public readonly string $isbn,
        public readonly string $title,
        private int $copiesAvailable,
    ) {}

    public function borrow(string $memberId): void
    {
        if ($this->copiesAvailable === 0) {
            throw new DomainException("No copies of '{$this->title}' available");
        }
        $this->copiesAvailable--;
        $this->recordEvent(new BookBorrowed($this->isbn, $memberId, new \DateTimeImmutable()));
    }

    public function copiesAvailable(): int
    {
        return $this->copiesAvailable;
    }
}

final class AggregateRootTest extends TestCase
{
    public function testBorrowRecordsEvent(): void
    {
        $book = new Book('978-...', 'Clean Code', 1);
        self::assertFalse($book->hasUncommittedEvents());

        $book->borrow('member-42');

        self::assertTrue($book->hasUncommittedEvents());
        self::assertSame(0, $book->copiesAvailable());
    }

    public function testPullDomainEventsDrainsQueue(): void
    {
        $book = new Book('978-...', 'Clean Code', 3);
        $book->borrow('m1');
        $book->borrow('m2');

        $events = $book->pullDomainEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(BookBorrowed::class, $events[0]);
        self::assertSame('m1', $events[0]->memberId);
        self::assertSame('m2', $events[1]->memberId);

        // Second pull returns empty.
        self::assertSame([], $book->pullDomainEvents());
        self::assertFalse($book->hasUncommittedEvents());
    }

    public function testInvariantViolationThrowsDomainException(): void
    {
        $book = new Book('978-...', 'Clean Code', 0);
        $this->expectException(DomainException::class);
        $book->borrow('any-member');
    }

    public function testEventCarriesItsOccurredOn(): void
    {
        $when  = new \DateTimeImmutable('2026-05-18 12:00:00');
        $event = new BookBorrowed('isbn', 'member', $when);
        self::assertSame($when, $event->occurredOn());
    }
}
