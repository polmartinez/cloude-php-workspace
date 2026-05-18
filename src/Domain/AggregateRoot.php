<?php

declare(strict_types=1);

namespace Cloude\Domain;

/**
 * Optional base for aggregate roots in DDD-shaped projects. Two
 * responsibilities:
 *
 *   1. **Event recording** — `recordEvent()` queues a `DomainEvent`
 *      inside the aggregate. The application layer calls
 *      `pullDomainEvents()` after a successful persistence call and
 *      dispatches the resulting list however it wants (log, queue,
 *      webhook, in-process subscribers).
 *
 *   2. **No identity / persistence opinions.** The framework does NOT
 *      assume the aggregate's PK shape, equality rules, or storage
 *      adapter. Subclasses define everything (constructor, factories,
 *      domain methods). This class only owns the event queue.
 *
 *   final class Book extends AggregateRoot
 *   {
 *       public function __construct(
 *           public readonly Isbn $isbn,
 *           public readonly string $title,
 *           private int $copiesAvailable,
 *       ) {}
 *
 *       public function borrow(MemberId $by, \DateTimeImmutable $when): void
 *       {
 *           if ($this->copiesAvailable === 0) {
 *               throw new DomainException("No copies of '{$this->title}' available");
 *           }
 *           $this->copiesAvailable--;
 *           $this->recordEvent(new BookBorrowed($this->isbn, $by, $when));
 *       }
 *   }
 *
 *   // In the application layer:
 *   $book->borrow($memberId, $now);
 *   $repo->save($book);
 *   foreach ($book->pullDomainEvents() as $event) {
 *       $eventLog->record($event);
 *   }
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    /**
     * Append a domain event to the queue. Call from inside a domain
     * method right after the state change it describes.
     */
    protected function recordEvent(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * Drain the queue. Returns the events in order, clearing the
     * aggregate so a second call returns an empty list. Call from the
     * application layer once the aggregate has been persisted.
     *
     * @return list<DomainEvent>
     */
    public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function hasUncommittedEvents(): bool
    {
        return $this->domainEvents !== [];
    }
}
