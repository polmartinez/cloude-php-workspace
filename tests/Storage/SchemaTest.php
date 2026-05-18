<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Schema;
use Cloude\Testing\TestCase;

final class SchemaTest extends TestCase
{
    public function testEmitsBasicCreateTable(): void
    {
        $sql = Schema::createTableSql('users', [
            'id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false, 'auto_increment' => true, 'primary' => true],
            'email' => ['type' => 'VARCHAR(255)', 'null' => false],
        ]);

        self::assertStringContainsString('CREATE TABLE `users`', $sql);
        self::assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $sql);
        self::assertStringContainsString('`email` VARCHAR(255) NOT NULL', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
    }

    public function testDefaultNullEmitsAsNull(): void
    {
        $sql = Schema::createTableSql('users', [
            'role_id' => ['type' => 'INT', 'null' => true, 'default' => null],
        ]);
        self::assertStringContainsString('`role_id` INT NULL DEFAULT NULL', $sql);
    }

    public function testDefaultStringIsQuotedSafelyAndSqlKeywordsArePassedThrough(): void
    {
        $sql = Schema::createTableSql('rows', [
            'comment'    => ['type' => 'VARCHAR(50)', 'null' => false, 'default' => "it's fine"],
            'created_at' => ['type' => 'DATETIME', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
        ]);
        self::assertStringContainsString("DEFAULT 'it''s fine'", $sql);
        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql);
    }

    public function testIndexesEmitInlineKeywords(): void
    {
        $sql = Schema::createTableSql(
            'users',
            ['id' => ['type' => 'INT', 'primary' => true], 'email' => ['type' => 'VARCHAR(255)']],
            [
                ['type' => 'unique', 'columns' => ['email']],
                ['type' => 'index',  'columns' => ['email'], 'name' => 'ix_email_lower'],
            ],
        );
        self::assertStringContainsString('UNIQUE KEY `uq_users_email` (`email`)', $sql);
        self::assertStringContainsString('KEY `ix_email_lower` (`email`)', $sql);
    }

    public function testForeignKeyWithOnDeleteAndOnUpdate(): void
    {
        $sql = Schema::createTableSql(
            'orders',
            [
                'id'      => ['type' => 'INT', 'primary' => true],
                'user_id' => ['type' => 'INT', 'null' => true],
            ],
            [],
            [
                [
                    'columns'    => ['user_id'],
                    'references' => 'users',
                    'on'         => ['id'],
                    'on_delete'  => 'set null',
                    'on_update'  => 'cascade',
                ],
            ],
        );

        self::assertStringContainsString(
            'CONSTRAINT `fk_orders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)',
            $sql,
        );
        self::assertStringContainsString('ON DELETE SET NULL', $sql);
        self::assertStringContainsString('ON UPDATE CASCADE', $sql);
    }

    public function testForeignKeyRejectsUnknownReferentialAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schema::createTableSql(
            'orders',
            ['id' => ['type' => 'INT', 'primary' => true], 'user_id' => ['type' => 'INT']],
            [],
            [
                [
                    'columns'    => ['user_id'],
                    'references' => 'users',
                    'on'         => ['id'],
                    'on_delete'  => 'bananas',
                ],
            ],
        );
    }

    public function testCompositePrimaryKeyDeclaredAtTableLevel(): void
    {
        $sql = Schema::createTableSql('pivot', [
            'a' => ['type' => 'INT', 'null' => false, 'primary' => true],
            'b' => ['type' => 'INT', 'null' => false, 'primary' => true],
        ]);
        self::assertStringContainsString('PRIMARY KEY (`a`, `b`)', $sql);
        // The inline `PRIMARY KEY` after each column has been stripped.
        self::assertSame(1, substr_count($sql, 'PRIMARY KEY'));
    }

    public function testPostgresDialectUsesDoubleQuotesAndNoEngine(): void
    {
        $sql = Schema::createTableSql(
            'users',
            ['id' => ['type' => 'SERIAL', 'primary' => true]],
            [['type' => 'unique', 'columns' => ['id']]],
            [],
            dialect: 'pgsql',
        );
        self::assertStringContainsString('CREATE TABLE "users"', $sql);
        self::assertStringNotContainsString('ENGINE=InnoDB', $sql);
    }

    public function testDropTableSql(): void
    {
        self::assertSame(
            'DROP TABLE IF EXISTS `users`',
            Schema::dropTableSql('users'),
        );
        self::assertSame(
            'DROP TABLE IF EXISTS "users"',
            Schema::dropTableSql('users', 'pgsql'),
        );
    }

    public function testEmptyColumnsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schema::createTableSql('users', []);
    }

    public function testBareCreateTableIsExecutableOnSqlite(): void
    {
        // Smoke test: a minimal CREATE TABLE (no indexes — SQLite's
        // index syntax differs from MySQL's `KEY ...` form) is
        // executable. Schema's primary target is MySQL/Postgres; full
        // SQLite parity isn't in scope.
        $sql = Schema::createTableSql(
            'users',
            [
                'id'    => ['type' => 'INTEGER', 'null' => false, 'primary' => true],
                'email' => ['type' => 'TEXT', 'null' => false],
            ],
            indexes: [],
            foreignKeys: [],
            dialect: 'pgsql',   // pgsql output omits the MySQL-only tail
        );

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($sql);

        $row = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        self::assertSame('users', $row['name'] ?? null);
    }
}
