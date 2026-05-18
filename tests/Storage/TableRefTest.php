<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Identifier;
use Cloude\Storage\TableRef;
use PHPUnit\Framework\TestCase;

final class TableRefTest extends TestCase
{
    public function testFieldWithoutAliasUsesTableName(): void
    {
        $t = new TableRef('users');
        self::assertSame('users', $t->name());
        self::assertSame('users.email', $t->field('email'));
        self::assertSame('users.*', $t->field('*'));
    }

    public function testFieldWithAliasUsesAlias(): void
    {
        $t = new TableRef('users', 'u');
        self::assertSame('u', $t->name());
        self::assertSame('u.email', $t->field('email'));
    }

    public function testExpressionWithoutAlias(): void
    {
        $t = new TableRef('users');
        self::assertSame('`users`', $t->expression());
    }

    public function testExpressionWithAlias(): void
    {
        $t = new TableRef('users', 'u');
        self::assertSame('`users` AS `u`', $t->expression());
    }

    public function testExpressionWithPostgresQuotes(): void
    {
        $t = new TableRef('users', 'u');
        self::assertSame('"users" AS "u"', $t->expression('"'));
    }

    public function testStringableProducesQuotedExpression(): void
    {
        self::assertSame('`users`', (string) new TableRef('users'));
        self::assertSame('`users` AS `u`', (string) new TableRef('users', 'u'));
    }

    public function testIdentifierQualifyHandlesDottedNames(): void
    {
        self::assertSame('*', Identifier::qualify('*'));
        self::assertSame('`email`', Identifier::qualify('email'));
        self::assertSame('`users`.`email`', Identifier::qualify('users.email'));
        self::assertSame('`users`.*', Identifier::qualify('users.*'));
        self::assertSame('"u"."email"', Identifier::qualify('u.email', '"'));
    }

    public function testIdentifierQualifyRejectsThreePartNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::qualify('db.users.email');
    }

    public function testIdentifierQualifyRejectsInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::qualify('users.id; DROP TABLE');
    }
}
