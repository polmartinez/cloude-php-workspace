<?php

declare(strict_types=1);

namespace Cloude\Testing;

use Cloude\Model\Storage;
use Cloude\Model\Storage\ArrayStorage;

/**
 * Test-only storage adapter that **delegates to an in-memory
 * `ArrayStorage` AND records every call** so tests can assert on the
 * exact methods, arguments and counts. Behaves like ArrayStorage for
 * everything else — `find()` actually returns the seeded row,
 * `save()` actually mutates the in-memory state, etc.
 *
 * Use it via {@see TestCase::useMockModel()}; the wrapper plus
 * `assertModelReceived()` / `assertModelDidNotReceive()` covers the
 * common "did my controller call `delete()` on the right record?"
 * scenarios.
 *
 * **What this doesn't mock: `Model::query()`.** The framework's SQL
 * builder is tightly coupled to PDO and faking its results from the
 * outside leads to brittle tests (the canned rows pass even when the
 * underlying SQL is wrong). For code that uses `Model::query()`,
 * use {@see TestCase::useSqliteModel()} — an in-memory SQLite is
 * faster than any mock you can write and tells you the truth about
 * your SQL.
 *
 *   $store = $this->useMockModel(User::class, [
 *       ['id' => 1, 'email' => 'ada@x', 'active' => 1],
 *   ]);
 *
 *   (new BanUserController())->ban(1);
 *
 *   $this->assertModelReceived($store, 'update', times: 1);
 *   self::assertSame([1, ['active' => 0]], $store->lastCall('update'));
 */
final class MockStorage implements Storage
{
    private ArrayStorage $inner;

    /**
     * Recorded calls in chronological order.
     *
     * @var list<array{method:string, args:array<int, mixed>}>
     */
    public array $calls = [];

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(array $rows = [], private string $primaryKey = 'id')
    {
        $this->inner = new ArrayStorage($rows, $primaryKey);
    }

    public function find(mixed $id): ?array
    {
        $this->record('find', [$id]);
        return $this->inner->find($id);
    }

    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array {
        $this->record('findBy', [$criteria, $limit, $offset, $orderBy]);
        return $this->inner->findBy($criteria, $limit, $offset, $orderBy);
    }

    public function count(array $criteria = []): int
    {
        $this->record('count', [$criteria]);
        return $this->inner->count($criteria);
    }

    public function insert(array $data): mixed
    {
        $this->record('insert', [$data]);
        return $this->inner->insert($data);
    }

    public function update(mixed $id, array $data): bool
    {
        $this->record('update', [$id, $data]);
        return $this->inner->update($id, $data);
    }

    public function delete(mixed $id): bool
    {
        $this->record('delete', [$id]);
        return $this->inner->delete($id);
    }

    // ── inspection helpers ────────────────────────────────────────────────

    /**
     * How many times $method was called.
     */
    public function callsTo(string $method): int
    {
        return count(array_filter($this->calls, static fn ($c) => $c['method'] === $method));
    }

    /**
     * Whether $method was called at least once (or exactly $times if given).
     */
    public function received(string $method, ?int $times = null): bool
    {
        $n = $this->callsTo($method);
        return $times === null ? $n > 0 : $n === $times;
    }

    /**
     * Arguments passed to the last call of $method, or null when none.
     *
     * @return array<int, mixed>|null
     */
    public function lastCall(string $method): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method) {
                return $call['args'];
            }
        }
        return null;
    }

    /**
     * Drop the call log. The seeded data is left intact — call this
     * between assertions if you want to reset the recorder without
     * losing rows.
     */
    public function resetCalls(): void
    {
        $this->calls = [];
    }

    /** Raw access to the underlying in-memory store (post-mutations). */
    public function rows(): ArrayStorage
    {
        return $this->inner;
    }

    /**
     * @param array<int, mixed> $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
