<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\ColumnNotFoundException;
use Cloude\Storage\ConnectionException;
use Cloude\Storage\DuplicateKeyException;
use Cloude\Storage\IntegrityConstraintException;
use Cloude\Storage\Query;
use Cloude\Storage\StorageException;
use Cloude\Storage\SyntaxErrorException;
use Cloude\Storage\TableNotFoundException;
use PHPUnit\Framework\TestCase;

final class StorageExceptionTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE,
            name  TEXT NOT NULL
        )');
    }

    public function testTableNotFoundWrapsAsSubclass(): void
    {
        try {
            (new Query($this->pdo, 'does_not_exist'))->get();
            self::fail('expected TableNotFoundException');
        } catch (TableNotFoundException $e) {
            self::assertInstanceOf(StorageException::class, $e);
            self::assertSame('HY000', substr($e->sqlState, 0, 5));   // SQLite reports HY000 for missing table
        } catch (StorageException $e) {
            // SQLite reports unknown tables under SQLSTATE HY000 (not 42S02);
            // accept the base wrapper in that case so the test stays
            // driver-agnostic. The dispatch is verified by the static
            // unit tests below.
            self::assertStringContainsString('does_not_exist', $e->getMessage());
        }
    }

    public function testColumnNotFoundOnQuery(): void
    {
        try {
            (new Query($this->pdo, 'users'))->where('not_a_column', 1)->get();
            self::fail('expected StorageException');
        } catch (StorageException $e) {
            // SQLite reports column issues under HY000 too — the message
            // still tells us the column is missing.
            self::assertStringContainsString('not_a_column', $e->getMessage());
            self::assertNotEmpty($e->sql);
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testDuplicateKeyOnInsert(): void
    {
        $q = new Query($this->pdo, 'users');
        $q->insert(['email' => 'ada@x', 'name' => 'Ada']);
        try {
            $q->insert(['email' => 'ada@x', 'name' => 'Ada 2']);
            self::fail('expected IntegrityConstraintException');
        } catch (IntegrityConstraintException $e) {
            // SQLite uses SQLSTATE 23000 with no driver code for UNIQUE
            // violations → wrap as the generic IntegrityConstraintException.
            self::assertSame('23000', $e->sqlState);
            self::assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }
    }

    // ── unit-level dispatch (driver-independent) ──────────────────────────

    public function testWrapDispatchesTableNotFoundForMysql(): void
    {
        $e = self::fakePdoException('42S02', 1146, "Base table or view not found: 1146 Table 'x' doesn't exist");
        $wrapped = StorageException::wrap($e, 'SELECT * FROM x', []);
        self::assertInstanceOf(TableNotFoundException::class, $wrapped);
        self::assertSame('42S02', $wrapped->sqlState);
        self::assertSame('SELECT * FROM x', $wrapped->sql);
    }

    public function testWrapDispatchesTableNotFoundForPostgres(): void
    {
        $e = self::fakePdoException('42P01', 0, 'relation "x" does not exist');
        self::assertInstanceOf(TableNotFoundException::class, StorageException::wrap($e, 'SELECT * FROM x'));
    }

    public function testWrapDispatchesColumnNotFound(): void
    {
        $e1 = self::fakePdoException('42S22', 1054, "Unknown column 'foo' in 'where clause'");
        $e2 = self::fakePdoException('42703', 0, 'column "foo" does not exist');
        self::assertInstanceOf(ColumnNotFoundException::class, StorageException::wrap($e1, 'SELECT foo FROM x'));
        self::assertInstanceOf(ColumnNotFoundException::class, StorageException::wrap($e2, 'SELECT foo FROM x'));
    }

    public function testWrapDispatchesDuplicateKey(): void
    {
        $mysql = self::fakePdoException('23000', 1062, "Duplicate entry 'a@b' for key 'email'");
        $pg    = self::fakePdoException('23505', 0, 'duplicate key value violates unique constraint');
        self::assertInstanceOf(DuplicateKeyException::class, StorageException::wrap($mysql, 'INSERT'));
        self::assertInstanceOf(DuplicateKeyException::class, StorageException::wrap($pg, 'INSERT'));
    }

    public function testWrapDispatchesIntegrityConstraintForOther23(): void
    {
        // MySQL 23000 with a non-1062 driver code (e.g. FK violation: 1452).
        $e = self::fakePdoException('23000', 1452, 'Cannot add or update a child row: foreign key constraint fails');
        $wrapped = StorageException::wrap($e, 'INSERT');
        self::assertInstanceOf(IntegrityConstraintException::class, $wrapped);
        self::assertNotInstanceOf(DuplicateKeyException::class, $wrapped);
    }

    public function testWrapDispatchesSyntaxError(): void
    {
        $e = self::fakePdoException('42000', 1064, 'You have an error in your SQL syntax');
        self::assertInstanceOf(SyntaxErrorException::class, StorageException::wrap($e, 'SEELECT 1'));
    }

    public function testWrapDispatchesConnection(): void
    {
        $e = self::fakePdoException('08001', 2002, "Can't connect to MySQL server");
        self::assertInstanceOf(ConnectionException::class, StorageException::wrap($e, ''));
    }

    public function testWrapFallsBackToBaseStorageException(): void
    {
        $e = self::fakePdoException('HY000', 1, 'some weird PDO state');
        $wrapped = StorageException::wrap($e, 'SELECT 1');
        self::assertInstanceOf(StorageException::class, $wrapped);
        self::assertNotInstanceOf(TableNotFoundException::class, $wrapped);
    }

    public function testWrappedExceptionRetainsSqlBindingsAndPrevious(): void
    {
        $e = self::fakePdoException('42S02', 1146, "Table 'x' doesn't exist");
        $wrapped = StorageException::wrap($e, 'SELECT * FROM x WHERE id = ?', [42]);
        self::assertSame('SELECT * FROM x WHERE id = ?', $wrapped->sql);
        self::assertSame([42], $wrapped->bindings);
        self::assertSame($e, $wrapped->getPrevious());
    }

    /** Build a PDOException with a populated $errorInfo array. */
    private static function fakePdoException(string $sqlState, int $driverCode, string $msg): \PDOException
    {
        $e = new \PDOException("SQLSTATE[$sqlState]: $msg", 0);
        $e->errorInfo = [$sqlState, $driverCode, $msg];
        return $e;
    }
}
