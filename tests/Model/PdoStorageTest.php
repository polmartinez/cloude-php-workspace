<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Model\Storage\PdoStorage;
use Cloude\Testing\TestCase;

/**
 * Exercises PdoStorage against an in-memory SQLite database — same
 * driver-agnostic API a MySQL deploy would use, but no external server
 * dependency for the test run.
 */
final class PdoStorageTest extends TestCase
{
    private \PDO $pdo;
    private PdoStorage $storage;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (
            id     INTEGER PRIMARY KEY AUTOINCREMENT,
            email  TEXT NOT NULL,
            name   TEXT NOT NULL,
            active INTEGER DEFAULT 1
        )');
        $this->storage = new PdoStorage($this->pdo, 'users');
    }

    public function testInsertAutoIncrementsId(): void
    {
        $id = $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada']);
        self::assertSame(1, $id);
        $id2 = $this->storage->insert(['email' => 'g@b.com', 'name' => 'Grace']);
        self::assertSame(2, $id2);
    }

    public function testInsertHonoursExplicitId(): void
    {
        $id = $this->storage->insert(['id' => 42, 'email' => 'a@b.com', 'name' => 'Ada']);
        self::assertSame(42, $id);
    }

    public function testFindByPrimaryKey(): void
    {
        $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada']);
        $row = $this->storage->find(1);
        self::assertNotNull($row);
        self::assertSame('Ada', $row['name']);
    }

    public function testFindReturnsNullForMissing(): void
    {
        self::assertNull($this->storage->find(404));
    }

    public function testFindByEquality(): void
    {
        $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada',   'active' => 1]);
        $this->storage->insert(['email' => 'g@b.com', 'name' => 'Grace', 'active' => 0]);
        $rows = $this->storage->findBy(['active' => 1]);
        self::assertCount(1, $rows);
        self::assertSame('Ada', $rows[0]['name']);
    }

    public function testFindByNullCriterion(): void
    {
        $this->pdo->exec("INSERT INTO users (email, name, active) VALUES ('x@b.com', 'X', NULL)");
        $rows = $this->storage->findBy(['active' => null]);
        self::assertCount(1, $rows);
    }

    public function testFindByOrderingAndLimit(): void
    {
        $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada']);
        $this->storage->insert(['email' => 'b@b.com', 'name' => 'Bea']);
        $this->storage->insert(['email' => 'c@b.com', 'name' => 'Cal']);
        $rows = $this->storage->findBy([], limit: 2, orderBy: ['name' => 'DESC']);
        self::assertCount(2, $rows);
        self::assertSame('Cal', $rows[0]['name']);
        self::assertSame('Bea', $rows[1]['name']);
    }

    public function testCount(): void
    {
        $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada',  'active' => 1]);
        $this->storage->insert(['email' => 'b@b.com', 'name' => 'Bea',  'active' => 0]);
        self::assertSame(2, $this->storage->count());
        self::assertSame(1, $this->storage->count(['active' => 1]));
    }

    public function testUpdate(): void
    {
        $id = $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada']);
        self::assertTrue($this->storage->update($id, ['name' => 'Ada Lovelace']));
        self::assertSame('Ada Lovelace', $this->storage->find($id)['name']);
    }

    public function testDelete(): void
    {
        $id = $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada']);
        self::assertTrue($this->storage->delete($id));
        self::assertNull($this->storage->find($id));
    }

    public function testRejectsBadIdentifier(): void
    {
        $bad = new PdoStorage($this->pdo, 'users; DROP TABLE users');
        $this->expectException(\InvalidArgumentException::class);
        $bad->find(1);
    }

    public function testPdoEscapeHatchExposed(): void
    {
        self::assertSame($this->pdo, $this->storage->pdo());
    }

    public function testPostgresQuoteCharOverride(): void
    {
        // We don't actually run against Postgres here; just verify the
        // override is wired and produces a different SQL shape.
        $pg = new PdoStorage($this->pdo, 'users', 'id', '"');
        // Should still work on SQLite (which accepts both backticks and "").
        $this->storage->insert(['email' => 'a@b.com', 'name' => 'Ada']);
        $row = $pg->find(1);
        self::assertSame('Ada', $row['name']);
    }
}
