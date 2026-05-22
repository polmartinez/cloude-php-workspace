<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Connection;
use Cloude\Storage\Transaction;
use Cloude\Testing\TestCase;

final class TransactionTest extends TestCase
{
    private \PDO $pdo;
    private string $connName;

    protected function setUp(): void
    {
        // Each test gets a unique connection name so per-connection
        // depth state can't leak between cases.
        $this->connName = 'tx_' . bin2hex(random_bytes(4));
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        Connection::set($this->connName, $this->pdo);
    }

    protected function tearDown(): void
    {
        Transaction::reset($this->connName);
        Connection::reset($this->connName);
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    }

    private function insert(string $label): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO items (label) VALUES (?)');
        $stmt->execute([$label]);
    }

    // ── basic begin/commit/rollback ─────────────────────────────────────

    public function testBeginCommitPersistsChanges(): void
    {
        Transaction::begin($this->connName);
        $this->insert('a');
        $this->insert('b');
        Transaction::commit($this->connName);

        self::assertSame(2, $this->rowCount());
        self::assertFalse(Transaction::inTransaction($this->connName));
        self::assertSame(0, Transaction::depth($this->connName));
    }

    public function testBeginRollbackDiscardsChanges(): void
    {
        Transaction::begin($this->connName);
        $this->insert('a');
        Transaction::rollback($this->connName);

        self::assertSame(0, $this->rowCount());
        self::assertFalse(Transaction::inTransaction($this->connName));
    }

    public function testInTransactionAndDepthTracking(): void
    {
        self::assertFalse(Transaction::inTransaction($this->connName));
        self::assertSame(0, Transaction::depth($this->connName));

        Transaction::begin($this->connName);
        self::assertTrue(Transaction::inTransaction($this->connName));
        self::assertSame(1, Transaction::depth($this->connName));

        Transaction::begin($this->connName);   // nested → SAVEPOINT
        self::assertSame(2, Transaction::depth($this->connName));

        Transaction::commit($this->connName);
        self::assertSame(1, Transaction::depth($this->connName));

        Transaction::commit($this->connName);
        self::assertSame(0, Transaction::depth($this->connName));
        self::assertFalse(Transaction::inTransaction($this->connName));
    }

    public function testCommitWithoutBeginThrows(): void
    {
        $this->expectException(\LogicException::class);
        Transaction::commit($this->connName);
    }

    public function testRollbackWithoutBeginIsNoOp(): void
    {
        // Safe to call in `catch` / `finally` blocks even when begin didn't run.
        Transaction::rollback($this->connName);
        self::assertFalse(Transaction::inTransaction($this->connName));
    }

    // ── nesting via SAVEPOINTs ──────────────────────────────────────────

    public function testInnerRollbackKeepsOuterChanges(): void
    {
        Transaction::begin($this->connName);
        $this->insert('outer');

        Transaction::begin($this->connName);
        $this->insert('inner-1');
        $this->insert('inner-2');
        Transaction::rollback($this->connName);    // → ROLLBACK TO SAVEPOINT

        Transaction::commit($this->connName);

        // Outer row survived; inner two were rolled back.
        self::assertSame(1, $this->rowCount());
        self::assertSame('outer', $this->pdo->query('SELECT label FROM items')->fetchColumn());
    }

    public function testOuterRollbackDropsEverything(): void
    {
        Transaction::begin($this->connName);
        $this->insert('outer');

        Transaction::begin($this->connName);
        $this->insert('inner');
        Transaction::commit($this->connName);      // inner committed (released savepoint)

        Transaction::rollback($this->connName);    // outer rolled back → drops everything

        self::assertSame(0, $this->rowCount());
    }

    // ── closure-based run() ─────────────────────────────────────────────

    public function testRunCommitsOnSuccessAndForwardsReturnValue(): void
    {
        $result = Transaction::run(function () {
            $this->insert('a');
            return 42;
        }, $this->connName);

        self::assertSame(42, $result);
        self::assertSame(1, $this->rowCount());
        self::assertFalse(Transaction::inTransaction($this->connName));
    }

    public function testRunRollsBackOnExceptionAndRethrows(): void
    {
        try {
            Transaction::run(function (): void {
                $this->insert('a');
                throw new \RuntimeException('boom');
            }, $this->connName);
            self::fail('expected exception not thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(0, $this->rowCount());
        self::assertFalse(Transaction::inTransaction($this->connName));
    }

    public function testNestedRunInnerThrowOnlyDropsInnerWork(): void
    {
        Transaction::run(function () {
            $this->insert('outer');

            try {
                Transaction::run(function (): void {
                    $this->insert('inner');
                    throw new \RuntimeException('inner failed');
                }, $this->connName);
            } catch (\RuntimeException) {
                // swallow — outer keeps going
            }

            $this->insert('outer-after');
        }, $this->connName);

        // Outer row + "outer-after" survived; "inner" was rolled back.
        $rows = $this->pdo->query('SELECT label FROM items ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['outer', 'outer-after'], $rows);
    }

    public function testNestedRunOuterThrowDropsEverything(): void
    {
        try {
            Transaction::run(function () {
                $this->insert('outer');
                Transaction::run(function () {
                    $this->insert('inner');
                }, $this->connName);
                throw new \RuntimeException('outer failed');
            }, $this->connName);
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('outer failed', $e->getMessage());
        }

        self::assertSame(0, $this->rowCount());
    }
}
