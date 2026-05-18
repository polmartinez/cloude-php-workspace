<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Identifier;
use Cloude\Storage\TableRef;
use Cloude\Testing\TestCase;

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

    public function testIdentifierQualifyHandlesAliasedColumns(): void
    {
        self::assertSame(
            '`name` AS `type_name`',
            Identifier::qualify('name AS type_name'),
        );
        self::assertSame(
            '`users`.`name` AS `user_name`',
            Identifier::qualify('users.name AS user_name'),
        );
        // Lower-case `as` keyword and extra whitespace.
        self::assertSame(
            '`name` AS `n`',
            Identifier::qualify('name   as   n'),
        );
    }

    public function testIdentifierQualifyHandlesAliasedTables(): void
    {
        self::assertSame(
            '`users` AS `u`',
            Identifier::qualify('users AS u'),
        );
        self::assertSame(
            '"users" AS "u"',
            Identifier::qualify('users AS u', '"'),
        );
    }

    public function testIdentifierQualifyRejectsExpressionAlias(): void
    {
        // The aliased side must be a single bare identifier.
        $this->expectException(\InvalidArgumentException::class);
        Identifier::qualify('COUNT(*) AS total');
    }

    public function testAliasHelperReturnsTuple(): void
    {
        $t = new TableRef('users');
        self::assertSame(['users.name', 'user_name'], $t->alias('name', 'user_name'));

        $u = new TableRef('users', 'u');
        self::assertSame(['u.name', 'who'], $u->alias('name', 'who'));
    }
}
