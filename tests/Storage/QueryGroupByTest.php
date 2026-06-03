<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Query;
use Cloude\Testing\TestCase;

/**
 * Covers the GROUP BY / HAVING / selectRaw additions:
 *
 *   $q->select('country')->selectRaw('COUNT(*)', 'n')
 *     ->groupBy('country')->havingRaw('COUNT(*) > ?', [1])
 *     ->orderBy('n', 'DESC')->get();
 *
 * Also pins down the count()-with-GROUP-BY semantics: returns the
 * number of groups (rows in the GROUP BY result), not a per-group
 * count.
 */
final class QueryGroupByTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE orders (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            country  TEXT,
            customer TEXT,
            amount   INTEGER
        )');
        $rows = [
            ['ES', 'Ada',   100],
            ['ES', 'Ada',   200],
            ['ES', 'Bea',    50],
            ['FR', 'Carl',  300],
            ['FR', 'Carl',  100],
            ['DE', 'Dora',   80],
        ];
        $stmt = $this->pdo->prepare('INSERT INTO orders (country, customer, amount) VALUES (?, ?, ?)');
        foreach ($rows as $r) {
            $stmt->execute($r);
        }
    }

    private function q(): Query
    {
        return new Query($this->pdo, 'orders');
    }

    public function testGroupByWithAggregateProducesOneRowPerGroup(): void
    {
        $rows = $this->q()
            ->select('country')
            ->selectRaw('COUNT(*)', 'n')
            ->selectRaw('SUM(amount)', 'total')
            ->groupBy('country')
            ->orderBy('country')
            ->get();

        self::assertCount(3, $rows);
        self::assertSame('DE', $rows[0]['country']);
        self::assertSame(1, $rows[0]['n']);
        self::assertSame(80, $rows[0]['total']);
        self::assertSame('ES', $rows[1]['country']);
        self::assertSame(3, $rows[1]['n']);
        self::assertSame(350, $rows[1]['total']);
        self::assertSame('FR', $rows[2]['country']);
        self::assertSame(2, $rows[2]['n']);
        self::assertSame(400, $rows[2]['total']);
    }

    public function testSelectRawClearsDefaultWildcard(): void
    {
        // Pure-aggregate query — selectRaw must drop the implicit `*`.
        $rows = $this->q()->selectRaw('COUNT(*)', 'n')->get();
        self::assertSame([['n' => 6]], $rows);
    }

    public function testSelectRawAppendsToExplicitSelect(): void
    {
        $rows = $this->q()
            ->select('country')
            ->selectRaw('COUNT(*)', 'n')
            ->groupBy('country')
            ->orderBy('country')
            ->get();

        self::assertSame(['country' => 'DE', 'n' => 1], $rows[0]);
    }

    public function testGroupByMultipleColumns(): void
    {
        $rows = $this->q()
            ->select('country')
            ->select('country', 'customer')
            ->selectRaw('SUM(amount)', 'total')
            ->groupBy('country', 'customer')
            ->orderBy('country')
            ->orderBy('customer')
            ->get();

        // Distinct (country, customer) pairs: 4 in fixture.
        self::assertCount(4, $rows);
        self::assertSame(['country' => 'DE', 'customer' => 'Dora', 'total' => 80], $rows[0]);
        self::assertSame(['country' => 'ES', 'customer' => 'Ada',  'total' => 300], $rows[1]);
        self::assertSame(['country' => 'ES', 'customer' => 'Bea',  'total' => 50],  $rows[2]);
        self::assertSame(['country' => 'FR', 'customer' => 'Carl', 'total' => 400], $rows[3]);
    }

    public function testHavingRawFiltersGroups(): void
    {
        // Only countries with at least 2 orders.
        $rows = $this->q()
            ->select('country')
            ->selectRaw('COUNT(*)', 'n')
            ->groupBy('country')
            ->havingRaw('COUNT(*) >= ?', [2])
            ->orderBy('country')
            ->get();

        self::assertCount(2, $rows);
        self::assertSame('ES', $rows[0]['country']);
        self::assertSame('FR', $rows[1]['country']);
    }

    public function testMultipleHavingRawAreAndJoined(): void
    {
        // ES: n=3 sum=350, FR: n=2 sum=400, DE: n=1 sum=80
        // Filter: n >= 2 AND sum >= 400 → only FR.
        $rows = $this->q()
            ->select('country')
            ->selectRaw('COUNT(*)', 'n')
            ->selectRaw('SUM(amount)', 'total')
            ->groupBy('country')
            ->havingRaw('COUNT(*) >= ?', [2])
            ->havingRaw('SUM(amount) >= ?', [400])
            ->get();

        self::assertCount(1, $rows);
        self::assertSame('FR', $rows[0]['country']);
    }

    public function testHavingRawWithoutBindings(): void
    {
        $rows = $this->q()
            ->select('country')
            ->selectRaw('COUNT(*)', 'n')
            ->groupBy('country')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('country')
            ->get();

        self::assertCount(2, $rows);
    }

    public function testCountWithGroupByReturnsNumberOfGroups(): void
    {
        // 3 distinct countries.
        $n = $this->q()->groupBy('country')->count();
        self::assertSame(3, $n);

        // 4 distinct (country, customer) pairs.
        $n = $this->q()->groupBy('country', 'customer')->count();
        self::assertSame(4, $n);
    }

    public function testCountWithHavingNarrowsGroups(): void
    {
        // Countries with >= 2 orders: ES + FR.
        $n = $this->q()
            ->groupBy('country')
            ->havingRaw('COUNT(*) >= ?', [2])
            ->count();
        self::assertSame(2, $n);
    }

    public function testCountWithoutGroupByStillWorksAsScalar(): void
    {
        self::assertSame(6, $this->q()->count());
        self::assertSame(3, $this->q()->where('country', 'ES')->count());
    }

    public function testCompileShowsGroupByAndHaving(): void
    {
        $sql = $this->q()
            ->select('country')
            ->selectRaw('COUNT(*)', 'n')
            ->groupBy('country')
            ->havingRaw('COUNT(*) > ?', [2])
            ->orderBy('n', 'DESC')
            ->compile();

        self::assertStringContainsString('GROUP BY `country`', $sql);
        self::assertStringContainsString('HAVING (COUNT(*) > 2)', $sql);
        self::assertStringContainsString("ORDER BY `n` DESC", $sql);
    }

    public function testWhereCombinesWithGroupByCorrectly(): void
    {
        // Same query plus pre-aggregate WHERE: only orders >= 100.
        $rows = $this->q()
            ->select('country')
            ->selectRaw('SUM(amount)', 'total')
            ->where('amount', '>=', 100)
            ->groupBy('country')
            ->orderBy('country')
            ->get();

        // ES: 100+200=300 (50 dropped). FR: 300+100=400. DE: 80 dropped → no row.
        self::assertCount(2, $rows);
        self::assertSame(['country' => 'ES', 'total' => 300], $rows[0]);
        self::assertSame(['country' => 'FR', 'total' => 400], $rows[1]);
    }
}
