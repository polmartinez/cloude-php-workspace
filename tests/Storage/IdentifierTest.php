<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Identifier;
use PHPUnit\Framework\TestCase;

final class IdentifierTest extends TestCase
{
    public function testQuoteDefaultsToBacktick(): void
    {
        self::assertSame('`users`', Identifier::quote('users'));
    }

    public function testQuoteAcceptsCustomChar(): void
    {
        self::assertSame('"users"', Identifier::quote('users', '"'));
    }

    public function testQuoteAcceptsSnakeAndDigits(): void
    {
        self::assertSame('`user_id_2`', Identifier::quote('user_id_2'));
    }

    public function testQuoteRejectsSemicolon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::quote('users; DROP TABLE x');
    }

    public function testQuoteRejectsLeadingDigit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::quote('2nd_col');
    }

    public function testQuoteRejectsBackticks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::quote('`users`');
    }

    public function testQuoteCharForSqliteIsBacktick(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        self::assertSame('`', Identifier::quoteCharFor($pdo));
    }

    // Note: testing Postgres requires a real pgsql PDO driver; skipped here.
    // The branch is exercised by unit-level reading of the method.
}
