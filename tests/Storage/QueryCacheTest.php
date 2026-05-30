<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Cache\Cache;
use Cloude\Cache\Store\ArrayStore;
use Cloude\Storage\Query;
use Cloude\Testing\TestCase;

/**
 * Verifies that `Query::cache()` actually short-circuits the SELECT
 * terminals. We swap the PDO out from under a primed cache so a real
 * hit returns the stale rows and a real miss explodes — gives us a
 * precise signal without relying on time-based TTL probes.
 */
final class QueryCacheTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        $this->pdo->exec("INSERT INTO items (id, label) VALUES (1, 'a'), (2, 'b'), (3, 'c')");

        Cache::reset();
        Cache::set('default', new ArrayStore());
    }

    protected function tearDown(): void
    {
        Cache::reset();
    }

    private function q(): Query
    {
        return new Query($this->pdo, 'items');
    }

    public function testGetIsCachedAndDoesNotHitDbOnReplay(): void
    {
        $first = $this->q()->cache(60)->get();
        self::assertCount(3, $first);

        // Wipe the table — a non-cached query would now return [].
        $this->pdo->exec('DELETE FROM items');

        $second = $this->q()->cache(60)->get();
        self::assertSame($first, $second, 'second call should hit the cache, not the empty table');
    }

    public function testDifferentWhereProducesDifferentCacheKey(): void
    {
        $all     = $this->q()->cache(60)->get();
        $filtered = $this->q()->cache(60)->where('label', 'a')->get();

        self::assertCount(3, $all);
        self::assertCount(1, $filtered);
        self::assertSame('a', $filtered[0]['label']);
    }

    public function testCountIsCachedSeparatelyFromGet(): void
    {
        $count = $this->q()->cache(60)->count();
        self::assertSame(3, $count);

        $this->pdo->exec("INSERT INTO items (id, label) VALUES (99, 'z')");

        self::assertSame(3, $this->q()->cache(60)->count(), 'count must hit cache');
        self::assertCount(4, $this->q()->get(), 'non-cached get sees the new row');
    }

    public function testFirstReusesGetCachePath(): void
    {
        $row = $this->q()->cache(60)->where('id', 1)->first();
        self::assertSame('a', $row['label']);

        $stmt = $this->pdo->prepare('UPDATE items SET label = ? WHERE id = 1');
        $stmt->execute(['MUTATED']);

        $row2 = $this->q()->cache(60)->where('id', 1)->first();
        self::assertSame('a', $row2['label'], 'cached first() must not see the update');
    }

    public function testPluckIsCached(): void
    {
        $labels = $this->q()->cache(60)->pluck('label');
        self::assertSame(['a', 'b', 'c'], $labels);

        $this->pdo->exec('DELETE FROM items');

        self::assertSame(['a', 'b', 'c'], $this->q()->cache(60)->pluck('label'));
    }

    public function testValueIsCached(): void
    {
        $v = $this->q()->cache(60)->where('id', 2)->value('label');
        self::assertSame('b', $v);

        $stmt = $this->pdo->prepare('UPDATE items SET label = ? WHERE id = 2');
        $stmt->execute(['B!']);

        self::assertSame('b', $this->q()->cache(60)->where('id', 2)->value('label'));
    }

    public function testExplicitKeyAllowsForget(): void
    {
        $this->q()->cache(60, 'items:all')->get();
        $this->pdo->exec('DELETE FROM items');

        // Cached.
        self::assertCount(3, $this->q()->cache(60, 'items:all')->get());

        // Manual invalidation:
        Cache::forget('items:all');
        self::assertCount(0, $this->q()->cache(60, 'items:all')->get());
    }

    public function testWithoutCacheCallEveryQueryHitsDb(): void
    {
        self::assertCount(3, $this->q()->get());
        $this->pdo->exec('DELETE FROM items');
        self::assertCount(0, $this->q()->get(), 'no cache() call → no caching');
    }

    public function testInsertUpdateDeleteIgnoreCacheFlag(): void
    {
        // cache() shouldn't break mutations even though the flag is set.
        $q = $this->q()->cache(60);
        $id = $q->insert(['id' => 99, 'label' => 'z']);
        self::assertSame(99, $id);

        $updated = $this->q()->cache(60)->where('id', 99)->update(['label' => 'Z']);
        self::assertSame(1, $updated);

        $deleted = $this->q()->cache(60)->where('id', 99)->delete();
        self::assertSame(1, $deleted);
    }

    public function testNamedStoreIsHonored(): void
    {
        $other = new ArrayStore();
        Cache::set('other', $other);

        $this->q()->cache(60, store: 'other')->get();
        $this->pdo->exec('DELETE FROM items');

        // Default store is empty — caching went to 'other'.
        self::assertCount(0, $this->q()->cache(60)->get());
        self::assertCount(3, $this->q()->cache(60, store: 'other')->get());
    }
}
