<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Model\Storage\ArrayStorage;
use PHPUnit\Framework\TestCase;

final class ArrayStorageTest extends TestCase
{
    public function testSeededRowsAreFindable(): void
    {
        $s = new ArrayStorage([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ]);
        self::assertSame('Ada', $s->find(1)['name']);
        self::assertSame('Grace', $s->find(2)['name']);
    }

    public function testAutoIncrementContinuesFromSeed(): void
    {
        $s = new ArrayStorage([['id' => 5, 'name' => 'Ada']]);
        $newId = $s->insert(['name' => 'Grace']);
        self::assertSame(6, $newId);
    }

    public function testInsertWithoutIdAssignsOne(): void
    {
        $s = new ArrayStorage();
        self::assertSame(1, $s->insert(['name' => 'Ada']));
        self::assertSame(2, $s->insert(['name' => 'Grace']));
    }

    public function testFindByOrderingDescending(): void
    {
        $s = new ArrayStorage([
            ['id' => 1, 'n' => 1],
            ['id' => 2, 'n' => 3],
            ['id' => 3, 'n' => 2],
        ]);
        $rows = $s->findBy([], orderBy: ['n' => 'DESC']);
        self::assertSame([3, 2, 1], array_column($rows, 'n'));
    }

    public function testFindByOffsetLimit(): void
    {
        $s = new ArrayStorage();
        for ($i = 1; $i <= 5; $i++) {
            $s->insert(['n' => $i]);
        }
        $rows = $s->findBy([], limit: 2, offset: 1, orderBy: ['n' => 'ASC']);
        self::assertSame([2, 3], array_column($rows, 'n'));
    }

    public function testCountWithCriteria(): void
    {
        $s = new ArrayStorage([
            ['id' => 1, 'active' => 1],
            ['id' => 2, 'active' => 0],
            ['id' => 3, 'active' => 1],
        ]);
        self::assertSame(3, $s->count());
        self::assertSame(2, $s->count(['active' => 1]));
    }

    public function testUpdateMergesNewFields(): void
    {
        $s = new ArrayStorage([['id' => 1, 'name' => 'Ada', 'role' => 'user']]);
        self::assertTrue($s->update(1, ['role' => 'admin']));
        self::assertSame(['id' => 1, 'name' => 'Ada', 'role' => 'admin'], $s->find(1));
    }

    public function testDeleteRemovesRow(): void
    {
        $s = new ArrayStorage([['id' => 1, 'name' => 'Ada']]);
        self::assertTrue($s->delete(1));
        self::assertNull($s->find(1));
        self::assertFalse($s->delete(1));   // already gone
    }
}
